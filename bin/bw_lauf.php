<?php
/**
 * Beschattungswaechter - der Lauf
 *
 * Wird vom Cron alle FUENF Minuten aufgerufen (cron/cron.05min) und
 * entscheidet selbst, ob er etwas tut: nur wenn eingeschaltet, nur im
 * Zeitfenster und nur, wenn seit dem letzten Befehl der eingestellte Abstand
 * vergangen ist. Der Abstand steht in der Konfiguration und nicht im
 * Ordnernamen des Crons - sonst muesste man beim Aendern eine Datei
 * verschieben.
 *
 * (Bis 0.9.10 stand hier "alle fuenfzehn Minuten". Der Takt war seit 0.9.2
 * ein anderer; der Satz ist nicht mitgezogen worden - und dieselbe Zahl
 * stand noch an drei weiteren Stellen.)
 *
 * DAS LEBENSZEICHEN GEHT BEI JEDEM DURCHGANG HINAUS, auch wenn nichts
 * gesendet wurde. Sonst ist ein toter Waechter von einem ruhigen Haus nicht
 * zu unterscheiden: die virtuellen Eingaenge behalten ihren letzten Wert.
 *
 * Aufruf von Hand:
 *   php bw_lauf.php            regulaerer Lauf
 *   php bw_lauf.php --jetzt    ohne Ruecksicht auf Abstand und Fenster
 *   php bw_lauf.php --probe    sagt nur, was er taete - sendet nichts
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

/* Ein unbekannter Schalter darf nicht ARBEITEN. Ein Tippfehler soll eine
   Antwort ergeben und keinen Lauf - bei einem Werkzeug, das etwas an eine
   fremde Anlage schickt, ist das nicht Kosmetik. */
$bw_argv = isset($argv) ? $argv : array();
$bw_bekannt = array('--jetzt', '--probe');
foreach ($bw_argv as $bw_i => $bw_a) {
    if ($bw_i === 0 || strncmp((string) $bw_a, '--', 2) !== 0) {
        continue;
    }
    if (!in_array($bw_a, $bw_bekannt, true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $bw_a . ' - bekannt sind '
                     . implode(', ', $bw_bekannt) . "\n");
        exit(2);
    }
}
$bw_jetzt = in_array('--jetzt', $bw_argv, true);
$bw_probe = in_array('--probe', $bw_argv, true);

$c = bw_config();
$stand = bw_stand_lesen();
$letzte = isset($stand['letzte']) ? (int) $stand['letzte'] : 0;
$alter = $letzte > 0 ? (time() - $letzte) : PHP_INT_MAX;
$ziele = bw_ziele($c);

/* NACH EINEM FEHLSCHLAG WIRD FRUEHER WIEDER ANGEKLOPFT.
 *
 * Bis 0.9.12 setzte der Lauf 'letzte' auch dann, wenn KEIN einziges Ziel
 * erreichbar war - der naechste Versuch kam damit erst nach dem vollen
 * Abstand. War der Miniserver in der einen Minute im Neustart, geschah mit
 * der Werkseinstellung bis zu einer Stunde nichts mehr, obwohl der Cron alle
 * fuenf Minuten anklopfte. Solange Fehler in Folge stehen, gilt deshalb die
 * kuerzere Frist. */
$bw_fehlerstand = isset($stand['fehler']) ? (int) $stand['fehler'] : 0;
$wartezeit = ($bw_fehlerstand > 0)
    ? min((int) $c['abstand'], 15) * 60
    : (int) $c['abstand'] * 60;

/* EIN HALBER TAKT TOLERANZ, und zwar aus einem gemessenen Grund.
 *
 * 'letzte' wird NACH dem Senden gesetzt, also ein paar Sekunden nach dem
 * Beginn des Laufs. Der Takt bei genau +60 Minuten sah damit 3598 Sekunden,
 * verglich sie mit 3600 und uebersprang - gesendet wurde erst fuenf Minuten
 * spaeter, und das wanderte mit jeder Stunde weiter. Aus 60 Minuten wurden
 * 65, aus dem kleinsten Abstand (5) wurden 10, also das Doppelte. */
$bw_toleranz = 150;

$grund = '';
/* Nicht jeder Grund, nichts zu tun, ist eine Stoerung: abgeschaltet und
   ausserhalb des Zeitfensters sind der bestimmungsgemaesse Betrieb. Nur ein
   fehlendes Ziel ist einer - und genau diese Unterscheidung traegt das Feld
   OK nach Loxone. */
$bw_stoerung = false;
if (!$ziele) {
    $grund = 'kein Ziel eingerichtet';
    $bw_stoerung = true;
} elseif (empty($c['aktiv']) && !$bw_jetzt) {
    $grund = 'abgeschaltet';
} elseif (!bw_im_fenster($c) && !$bw_jetzt) {
    $grund = 'ausserhalb des Zeitfensters ' . $c['von'] . '-' . $c['bis'];
} elseif ($alter + $bw_toleranz < $wartezeit && !$bw_jetzt) {
    $grund = sprintf('erst %d von %d Minuten seit dem letzten Befehl',
                     (int) floor($alter / 60), (int) round($wartezeit / 60));
}

if ($bw_probe) {
    /* Der Trockenlauf laeuft durch DENSELBEN Weg - alle Wachen greifen echt,
       nur das Senden unterbleibt. Und er uebernimmt NICHT den Wortlaut des
       Ernstfalls: eine Probe, die "gesendet" meldet, waehrend nichts
       gesendet wurde, ist eine stille Falschaussage. */
    bw_trocken(true);
    echo 'PROBE - es wird nichts gesendet.' . "\n";
    if ($grund !== '') {
        echo '  Ein regulaerer Lauf taete jetzt nichts: ' . $grund . "\n";
    }
    foreach ($ziele as $z) {
        $r = bw_senden($c, $z['uuid'], $z['befehl']);
        echo sprintf('  Ziel %d: %s an %s  ->  %s', $z['nr'], $z['befehl'], $z['uuid'],
                     $r['ok'] ? $r['url'] : $r['text']) . "\n";
    }
    if (!$ziele) { echo '  Es ist kein Ziel eingerichtet.' . "\n"; }
    bw_trocken(false);
    exit(0);
}

if ($grund !== '') {
    /* Auch ein Lauf, der nichts sendet, gibt ein Lebenszeichen ab: ZAEHLER
       und der Zeitstempel gehen weiter.
     *
     * OK sagt dabei NICHT "es wurde gesendet", sondern "der Durchgang endete
     * ohne Stoerung". Bis 0.9.12 stand hier fest false, und damit stand OK
     * mit der Werkseinstellung 55 von 60 Minuten je Stunde auf 0 und nachts
     * durchgehend - ohne dass irgendetwas war. Wer darauf in Loxone eine
     * Ueberwachung legte, hatte eine Dauerstoerung. */
    bw_lauf_schreiben(!$bw_stoerung);
    bw_mqtt_lebenszeichen($c);
    bw_melden($c);
    echo "nichts zu tun: " . $grund . "\n";
    exit(0);
}

/* Erst ab hier wird gesendet - also erst ab hier die Sperre. Sie ist nicht
   blockierend: wer nicht drankommt, geht wieder, der naechste Takt kommt in
   fuenf Minuten ohnehin. Die Datei muss offen BLEIBEN, sonst faellt die
   Sperre sofort. */
$bw_sperre = bw_sperre();
if ($bw_sperre === false) {
    echo "nichts zu tun: ein anderer Lauf ist noch unterwegs\n";
    exit(0);
}

$gut = 0;
/* ZWEI CODES, NICHT EINER. Bis 0.9.12 stand hier ein unbedingtes
   $code = $r['code'] in der Schleife - bei zwei Zielen, von denen das erste
   tot und das zweite gut war, meldete der Stand HTTP 200 UND einen erhoehten
   Fehlerzaehler. Der Healthcheck sagte dann "1 Fehler, HTTP 200", und das ist
   keine Auskunft, sondern ein Raetsel. Gilt der Code des ERSTEN Fehlschlags;
   erst wenn keiner fehlschlug, der des letzten guten Ziels. */
$code_fehler = 0;
$code_gut = 0;
$letzter_text = '';
foreach ($ziele as $z) {
    $r = bw_senden($c, $z['uuid'], $z['befehl']);
    if ($r['ok']) {
        $gut++;
        $code_gut = (int) $r['code'];
        continue;
    }
    $letzter_text = $r['text'];
    if ($code_fehler === 0) { $code_fehler = (int) $r['code']; }
    /* Die Bremse steht hier, seit der Fehlschlag alle 15 Minuten statt
       stuendlich wiederholt wird: sonst schreibt ein toter Miniserver das
       Protokoll voll und draengt genau die Zeilen weg, die erklaeren, wann
       es angefangen hat. Der HTTP-Code steht IM Merker - eine andere Art zu
       scheitern wird dadurch sofort sichtbar und nicht erst in einer
       Stunde. */
    bw_log_wenn_neu('senden' . $z['nr'] . '_' . (int) $r['code'],
        sprintf('FEHLER beim Senden an Ziel %d: HTTP %d %s  (%s)',
                $z['nr'], $r['code'], $r['text'], $r['url']));
}
$code = ($gut === count($ziele)) ? $code_gut : $code_fehler;
$alles = ($gut === count($ziele));

$heute = date('Y-m-d');
$letzter_tag = isset($stand['tag']) ? (string) $stand['tag'] : '';

$stand['letzte'] = time();
$stand['letzte_ok'] = $alles ? time() : (isset($stand['letzte_ok']) ? $stand['letzte_ok'] : 0);
$stand['gesendet'] = (isset($stand['gesendet']) ? (int) $stand['gesendet'] : 0) + 1;
$stand['fehler'] = $alles ? 0 : ((isset($stand['fehler']) ? (int) $stand['fehler'] : 0) + 1);
$stand['code'] = $code;
$stand['tag'] = $heute;

/* Die Wirkung messen, nicht den Rueckgabewert - aber nur, wenn der Anwender
   es eingeschaltet hat, und hoechstens einmal je Viertelstunde: die Messung
   fragt je Jalousie mehrere Zustaende ab. */
if (!empty($c['pruefen_ein'])) {
    $letzte_pruefung = isset($stand['scharf_ts']) ? (int) $stand['scharf_ts'] : 0;
    if (time() - $letzte_pruefung >= 900) {
        list($pok, $pmeldung, $perg) = bw_automatiken($c);
        if ($pok) {
            $stand['scharf'] = (int) $perg['scharf'];
            $stand['automatiken'] = (int) $perg['gesamt'];
            $stand['scharf_ts'] = time();
        } else {
            bw_log_wenn_neu('pruefen', 'Die Automatiken liessen sich nicht zaehlen: '
                                     . strip_tags((string) $pmeldung));
        }
    }
}

/* EINMAL schreiben, nicht zweimal. Bis 0.9.10 stand hier ein zweiter
   Schreibvorgang, nur damit der Tag nachkam - auf einer Speicherkarte ist
   das die doppelte Schreiblast fuer nichts. */
bw_stand_schreiben($stand);
bw_lauf_schreiben($alles);
bw_mqtt_publish($c, $stand);
bw_melden($c);

/* Aufgeschrieben wird der ERSTE Befehl des Tages und jeder Fehler - nicht
   jeder Lauf. Ein Fuenfminutentakt erzeugt sonst dreihundert Zeilen am Tag,
   in denen die eine wichtige untergeht. */
if ($alles && $letzter_tag !== $heute) {
    bw_log(sprintf('erster Befehl des Tages abgesetzt: %d Ziel(e), HTTP %d',
                   count($ziele), $code));
}

echo ($alles ? 'gesendet' : 'FEHLER') . ': ' . $gut . ' von ' . count($ziele)
   . ' Ziel(en), HTTP ' . $code . ' ' . substr($letzter_text, 0, 100) . "\n";
exit($alles ? 0 : 1);
