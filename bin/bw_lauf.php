<?php
/**
 * Beschattungswaechter - der Lauf
 *
 * Wird vom Cron alle fuenfzehn Minuten aufgerufen und entscheidet selbst, ob
 * er etwas tut: nur wenn eingeschaltet, nur im Zeitfenster und nur, wenn seit
 * dem letzten Befehl der eingestellte Abstand vergangen ist.
 *
 * Aufruf von Hand:
 *   php bw_lauf.php            regulaerer Lauf
 *   php bw_lauf.php --jetzt    ohne Ruecksicht auf Abstand und Fenster
 *   php bw_lauf.php --probe    sagt nur, was er taete
 */

$bw_lib = '';
foreach (array(
    getenv('LBPHTMLDIR') ? getenv('LBPHTMLDIR') . '/bw_lib.php' : '',
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/bw_lib.php',
    dirname(__DIR__) . '/webfrontend/html/bw_lib.php',
    __DIR__ . '/bw_lib.php',
) as $bw_k) {
    if ($bw_k !== '' && is_file($bw_k)) { $bw_lib = $bw_k; break; }
}
if ($bw_lib === '') {
    fwrite(STDERR, "bw_lib.php wurde nicht gefunden.\n");
    exit(1);
}
require_once $bw_lib;

$bw_argv = isset($argv) ? $argv : array();
$bw_jetzt = in_array('--jetzt', $bw_argv, true);
$bw_probe = in_array('--probe', $bw_argv, true);

$c = bw_config();
$stand = bw_stand_lesen();
$letzte = isset($stand['letzte']) ? (int) $stand['letzte'] : 0;
$alter = $letzte > 0 ? (time() - $letzte) : PHP_INT_MAX;

$grund = '';
if (empty($c['aktiv']) && !$bw_jetzt) {
    $grund = 'abgeschaltet';
} elseif (!bw_im_fenster($c) && !$bw_jetzt) {
    $grund = 'ausserhalb des Zeitfensters ' . $c['von'] . '-' . $c['bis'];
} elseif ($alter < (int) $c['abstand'] * 60 && !$bw_jetzt) {
    $grund = sprintf('erst %d von %d Minuten seit dem letzten Befehl',
                     (int) floor($alter / 60), (int) $c['abstand']);
}

if ($grund !== '') {
    echo "nichts zu tun: " . $grund . "\n";
    exit(0);
}
if ($bw_probe) {
    echo "wuerde senden: " . $c['befehl'] . " an " . $c['uuid'] . "\n";
    exit(0);
}

$r = bw_senden($c);
$stand['letzte'] = time();
$stand['letzte_ok'] = $r['ok'] ? time() : (isset($stand['letzte_ok']) ? $stand['letzte_ok'] : 0);
$stand['gesendet'] = (isset($stand['gesendet']) ? (int) $stand['gesendet'] : 0) + 1;
$stand['fehler'] = $r['ok'] ? 0 : ((isset($stand['fehler']) ? (int) $stand['fehler'] : 0) + 1);
$stand['code'] = $r['code'];
bw_stand_schreiben($stand);

/* Aufgeschrieben wird der ERSTE Befehl des Tages und jeder Fehler - nicht
   jeder Lauf. Ein Vierteilstundentakt erzeugt sonst hundert Zeilen am Tag, in
   denen die eine wichtige untergeht. */
$heute = date('Y-m-d');
$letzter_tag = isset($stand['tag']) ? (string) $stand['tag'] : '';
if (!$r['ok']) {
    bw_log(sprintf('FEHLER beim Senden: HTTP %d %s  (%s)', $r['code'], $r['text'], $r['url']));
} elseif ($letzter_tag !== $heute) {
    bw_log(sprintf('erster Befehl des Tages abgesetzt: %s an %s (HTTP %d)',
                   $c['befehl'], $c['uuid'], $r['code']));
}
$stand['tag'] = $heute;
bw_stand_schreiben($stand);

echo ($r['ok'] ? 'gesendet' : 'FEHLER') . ': HTTP ' . $r['code']
   . ' ' . substr($r['text'], 0, 120) . "\n";
exit($r['ok'] ? 0 : 1);
