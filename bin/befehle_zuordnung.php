<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Beschattungswaechter (linieneigen).
 *
 * Gruppe 23 (0.9.31; Entscheidung 3 V1, Entwurf entwurf_gruppe23_bw.txt 3.1, E-E1/E-E2; Bauart Docker NG 1.3.20 /
 * Ferien 1.2.28 bin/befehle_zuordnung.php). Die Bruecke selbst (bin/befehlsbruecke.php, .sh) ist die gemeinsame
 * Stammfassung 1.0.1 und wird hier nicht veraendert.
 *
 * Zwei Tasten (Art puls, Wert 1):
 *   jetzt   - das A an alle Ziele druecken, EINGREIFEND: es holt auch eine absichtlich abgeschaltete Automatik zurueck.
 *             Zweiter Haken ist der Hauptschalter "Eingeschaltet" (aktiv, E-E1) - kein neuer Schluessel; derselbe
 *             Schalter laesst den HTTP-Weg "jetzt" ablehnen (ERR=ABGESCHALTET), beide Wege bleiben gleich.
 *   pruefen - die Automatiken zaehlen (liest nur; ob gezaehlt wird, entscheidet bw_aktion_pruefen() mit dem Haken
 *             "Die Wirkung messen").
 * 'tun' ruft DIESELBEN Funktionen wie der HTTP-Endpunkt und die Knoepfe der Oberflaeche: bw_aktion_jetzt() und
 * bw_aktion_pruefen() mit Quelle 'mqtt' (Sperre lauf.lock, Stand unter der Sperre frisch, Gesamtfrist 90 s unter der
 * Waechtergrenze 180 s, Strukturdatei frisch - N4, N12). einstellungen() liest die Konfiguration bei jedem Aufruf
 * frisch (bw_config(false), kein static, ohne Heilen - der Dienst schreibt keine Konfiguration).
 *
 * Die Datei setzt keine Konfiguration und schreibt nichts.
 */
if (!function_exists('bw_config')) {
    /* Ladeblock wie Docker NG/Ferien: ausdruecklich LBHOMEDIR + LBPPLUGINDIR (Pruefstaende) zuerst, dann installiert
     * (bin/plugins/<ordner> -> webfrontend/html/plugins/<ordner>) ODER Archiv (bin/ neben webfrontend/). */
    $bw_kand = array();
    $bw_lb = (string) getenv('LBHOMEDIR');
    $bw_ordner = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    if ($bw_lb !== '' && $bw_ordner !== ''
        && !in_array($bw_ordner, array('.', '..', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true)) {
        $bw_kand[] = $bw_lb . '/webfrontend/html/plugins/' . $bw_ordner . '/bw_lib.php';
    }
    if (basename(dirname(__DIR__)) === 'plugins') {
        $bw_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/bw_lib.php';
    } else {
        $bw_kand[] = dirname(__DIR__) . '/webfrontend/html/bw_lib.php';
    }
    foreach ($bw_kand as $bw_k) {
        if (is_file($bw_k)) { require_once $bw_k; break; }
    }
}
// array('ok' => bool, 'grund' => string) der Linie -> array(ok, grund) der Bruecke
$bw_um = function ($r) {
    return array(!empty($r['ok']), isset($r['grund']) ? (string) $r['grund'] : '');
};
return array(
    'fassung' => 1,
    'linie'   => 'beschattungswaechter',
    'plugin'  => 'Beschattungswaechter',
    'geladen' => function_exists('bw_config') && function_exists('bw_aktion_jetzt') && function_exists('bw_aktion_pruefen'),
    'zweiter_haken' => 'aktiv',
    'einstellungen' => function () {
        $c = bw_config(false);
        return array('praefix'     => bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : ''),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_ein']),
                     'eingreifend' => !empty($c['aktiv']),
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) { bw_log(($stufe === 'INFO' ? '' : $stufe . ': ') . $text); },
    'befehle' => array(
        'jetzt'   => array('art' => 'puls', 'eingreifend' => true,
                           'titel' => 'Beschattungswaechter A jetzt druecken (MQTT)',
                           'kachel' => 'BEFEHL.K_JETZT', 'quittung' => 'ausgefuehrt',
                           'tun' => function ($w, $t) use ($bw_um) { return $bw_um(bw_aktion_jetzt(bw_config(false), 'mqtt')); }),
        'pruefen' => array('art' => 'puls', 'eingreifend' => false,
                           'titel' => 'Beschattungswaechter Automatiken zaehlen (MQTT)',
                           'kachel' => 'BEFEHL.K_PRUEFEN', 'quittung' => 'ausgefuehrt',
                           'tun' => function ($w, $t) use ($bw_um) { return $bw_um(bw_aktion_pruefen(bw_config(false), 'mqtt')); }),
    ),
);
