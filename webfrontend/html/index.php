<?php
/**
 * Beschattungswaechter - der Endpunkt fuer den Miniserver
 *
 * Er liegt im UNANGEMELDETEN Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist durch ein Merkwort geschuetzt:
 *
 *   /plugins/<ordner>/index.php?token=<MERKWORT>&aktion=<Befehl>
 *
 * Bis 0.9.10 gab es ihn nicht. Das Plugin war damit eine Einbahnstrasse: es
 * schickte Befehle an den Miniserver, und Loxone erfuhr nie, ob der Waechter
 * ueberhaupt noch lebt. Ein virtueller Eingang behaelt seinen letzten Wert -
 * ein toter Waechter sah aus wie ein ruhiges Haus.
 *
 * VIER FESTLEGUNGEN, jede aus einem Vorfall dieser Sammlung:
 *
 * 1. ER LEGT NICHTS AN. bw_config(false) liest nur. Bei EVCC, Govee und dem
 *    Saugroboter genuegte ein einziger Aufruf OHNE Merkwort - korrekt mit 403
 *    beantwortet -, und danach lag eine frisch erzeugte Konfigurationsdatei
 *    samt Merkwort im Ordner. Wer sich nicht ausweisen kann, hinterlaesst
 *    keine Datei, auch keine harmlose.
 *
 * 2. DER LEERE FALL WIRD VOR DEM VERGLEICH ABGEFANGEN. hash_equals('', '')
 *    ist in PHP TRUE - ohne diese Pruefung stuende der Endpunkt offen,
 *    solange kein Merkwort gesetzt ist.
 *
 * 3. JEDER PARAMETER GEHT ERST DURCH is_string(). '?token[]=x' macht daraus
 *    ein Feld; ein trim() darauf ist unter PHP 8 ein TypeError, und die
 *    Anfrage endet mit HTTP 500 und LEEREM Rumpf - der Miniserver bekommt
 *    dann gar nichts zu lesen statt einer Fehlermeldung.
 *
 * 4. JEDER WEG SCHREIBT EINE PROTOKOLLZEILE - auch die Abweisung. Ein
 *    fremdes Geraet kann sich nicht beschweren; bleibt eine Wirkung aus, ist
 *    das Protokoll die einzige Stelle, an der steht, ob ueberhaupt jemand
 *    angerufen hat. Das Merkwort selbst steht nie darin.
 *
 * (c) Beschattungswaechter Plugin Authors - MIT-Lizenz
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt in DIESEM Verzeichnis - installiert wie im Archiv.
   Eine Kandidatenliste braucht es hier nicht; sie braucht die Oberflaeche,
   die aus dem anderen Baum kommt. */
$bw_lib = __DIR__ . '/bw_lib.php';
if (!is_file($bw_lib)) {
    /* Die durchsuchten Pfade gehoeren ins Fehlerprotokoll des Webservers,
       NICHT in die Antwort: an dieser Stelle hat sich der Aufrufer noch
       nicht ausgewiesen. */
    error_log('Beschattungswaechter: bw_lib.php nicht gefunden, gesucht in ' . $bw_lib);
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "BW;OK=0;GRUND=BIBLIOTHEK_FEHLT\n";
    exit;
}
require_once $bw_lib;

header('Content-Type: text/plain; charset=utf-8');

/** Antworten und aufhoeren. */
function bw_ende($code, $zeile, $protokoll = '')
{
    http_response_code((int) $code);
    echo $zeile . "\n";
    if ($protokoll !== '') {
        $von = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '?';
        bw_log('Endpunkt (' . $von . '): ' . $protokoll);
    }
    exit;
}

/** Einen Parameter holen - was kein Skalar ist, gibt es nicht. */
function bw_par($name)
{
    if (isset($_GET[$name]) && is_string($_GET[$name])) {
        return trim($_GET[$name]);
    }
    if (isset($_POST[$name]) && is_string($_POST[$name])) {
        return trim($_POST[$name]);
    }
    /* Nie aus $_REQUEST: was dort steht, haengt von request_order ab, und die
       Vorgabe schliesst COOKIES ein. Ein Cookie namens token haette die
       Pruefung gefuettert. */
    return '';
}

$bw_cfg = bw_config(false);
$bw_soll = isset($bw_cfg['aktionstoken']) ? trim((string) $bw_cfg['aktionstoken']) : '';
$bw_ist = bw_par('token');
$bw_selftest = bw_par('selftest') === '1';

/* ---------------- Das Merkwort ---------------- *
 *
 * Der Selbsttest steht UNMITTELBAR hinter der Pruefung und vor jeder
 * Wirkung: er beantwortet genau eine Frage - stimmt das Merkwort - und
 * sonst nichts. Kein Geraetekontakt, kein Schreibzugriff.
 *
 * Ein falsches Merkwort bekommt dieselbe Abweisung wie sonst auch; der
 * Selbsttest darf keine Abkuerzung an der Sicherheit vorbei sein. */
if ($bw_soll === '') {
    bw_ende(403, ($bw_selftest ? 'SELFTEST' : 'BW') . ';OK=0;ERR=KEIN_TOKEN_EINGERICHTET',
            'abgewiesen - es ist kein Merkwort eingerichtet');
}
if ($bw_ist === '' || !hash_equals($bw_soll, $bw_ist)) {
    bw_ende(403, ($bw_selftest ? 'SELFTEST' : 'BW') . ';OK=0;ERR=TOKEN',
            'abgewiesen - falsches oder fehlendes Merkwort');
}
if ($bw_selftest) {
    bw_ende(200, 'SELFTEST;OK=1;TOKEN=OK', 'Selbsttest, nichts ausgeloest');
}

/* ---------------- Die Aktion ---------------- */
$bw_aktion = bw_par('aktion');
if ($bw_aktion === '') { $bw_aktion = 'status'; }
$bw_erlaubt = array('status', 'json', 'jetzt', 'pruefen');
if (!in_array($bw_aktion, $bw_erlaubt, true)) {
    /* Abgewiesen und GEMELDET - nicht zurechtgebogen. */
    bw_ende(400, 'BW;OK=0;ERR=AKTION_UNBEKANNT',
            'unbekannte Aktion abgewiesen: ' . substr(preg_replace('/[^\w\-]/', '', $bw_aktion), 0, 20));
}

if ($bw_aktion === 'status') {
    bw_ende(200, bw_statuszeile($bw_cfg));
}

if ($bw_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $bw_js = json_encode(bw_werte($bw_cfg), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    bw_ende(200, is_string($bw_js) ? $bw_js : '{}');
}

if ($bw_aktion === 'jetzt') {
    /* Der Anlass statt der Uhr: Loxone loest im Augenblick aus, in dem die
       Beschattungsfreigabe kommt, statt bis zu sechzig Minuten spaeter. Das
       Zeitfenster und der Abstand gelten hier bewusst NICHT - wer den Befehl
       schickt, meint ihn. */
    $bw_ziele = bw_ziele($bw_cfg);
    if (!$bw_ziele) {
        bw_ende(409, 'BW;OK=0;ERR=KEIN_ZIEL', 'jetzt: kein Ziel eingerichtet');
    }
    $bw_gut = 0;
    $bw_code = 0;
    foreach ($bw_ziele as $bw_z) {
        $bw_r = bw_senden($bw_cfg, $bw_z['uuid'], $bw_z['befehl']);
        if ($bw_r['ok']) { $bw_gut++; }
        $bw_code = (int) $bw_r['code'];
    }
    $bw_st = bw_stand_lesen();
    $bw_st['letzte'] = time();
    if ($bw_gut > 0) { $bw_st['letzte_ok'] = time(); }
    $bw_st['gesendet'] = (isset($bw_st['gesendet']) ? (int) $bw_st['gesendet'] : 0) + 1;
    $bw_st['fehler'] = ($bw_gut === count($bw_ziele))
        ? 0 : ((isset($bw_st['fehler']) ? (int) $bw_st['fehler'] : 0) + 1);
    $bw_st['code'] = $bw_code;
    bw_stand_schreiben($bw_st);
    bw_lauf_schreiben($bw_gut === count($bw_ziele));
    /* Ein Ausloeser meldet SOFORT, nicht beim naechsten Cron. Ueber HTTP
       holt der Miniserver den Wert beim naechsten Abruf ab; ueber MQTT muss
       ihn das Plugin schicken - sonst sieht ein Test, der erst eine Minute
       spaeter wirkt, aus wie einer, der nicht wirkt. */
    bw_mqtt_publish($bw_cfg, $bw_st);
    bw_ende($bw_gut === count($bw_ziele) ? 200 : 502,
            bw_statuszeile($bw_cfg, $bw_st),
            'jetzt: ' . $bw_gut . ' von ' . count($bw_ziele) . ' Zielen, HTTP ' . $bw_code);
}

if ($bw_aktion === 'pruefen') {
    if (empty($bw_cfg['pruefen_ein'])) {
        /* Ab Werk aus - und das wird gesagt, nicht stillschweigend als 0
           beantwortet. Eine 0 saehe aus wie ein Messwert. */
        bw_ende(409, 'BW;OK=0;ERR=PRUEFEN_AUS', 'pruefen: abgeschaltet');
    }
    list($bw_ok, $bw_meldung, $bw_erg) = bw_automatiken($bw_cfg);
    if (!$bw_ok) {
        bw_ende(502, 'BW;OK=0;ERR=PRUEFEN_MISSLUNGEN',
                'pruefen misslungen: ' . strip_tags((string) $bw_meldung));
    }
    $bw_st = bw_stand_lesen();
    $bw_st['scharf'] = (int) $bw_erg['scharf'];
    $bw_st['automatiken'] = (int) $bw_erg['gesamt'];
    $bw_st['scharf_ts'] = time();
    bw_stand_schreiben($bw_st);
    bw_mqtt_publish($bw_cfg, $bw_st);
    bw_ende(200, bw_statuszeile($bw_cfg, $bw_st),
            'pruefen: ' . $bw_erg['scharf'] . ' von ' . $bw_erg['gesamt'] . ' Automatiken scharf');
}
