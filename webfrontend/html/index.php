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
 * 1. ER LEGT KEINE KONFIGURATION AN. bw_config(false) liest nur. Bei EVCC,
 *    Govee und dem Saugroboter genuegte ein einziger Aufruf OHNE Merkwort -
 *    korrekt mit 403 beantwortet -, und danach lag eine frisch erzeugte
 *    Konfigurationsdatei samt Merkwort im Ordner. Wer sich nicht ausweisen
 *    kann, bekommt kein Merkwort geschenkt.
 *
 *    Die Protokollzeile aus Festlegung 4 entsteht sehr wohl, und mit ihr die
 *    Protokolldatei. Bis 0.9.12 stand hier "hinterlaesst keine Datei, auch
 *    keine harmlose" - das widersprach der vierten Festlegung im selben Kopf,
 *    und der Code folgte der vierten. Gemeint war immer die Konfiguration.
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
 *    ABER: die ABWEISUNG wird gebremst, je Aufrufer und Grund hoechstens
 *    einmal je Stunde. Nachgemessen am 29.08.2026: bw_log() kappt bei
 *    262144 Byte auf die letzten 800 Zeilen, und rund 2800 Aufrufe ohne
 *    Merkwort haben damit die gesamte Vorgeschichte weggedraengt. Ein
 *    Protokoll, das jeder Unbefugte leeren kann, ist an genau der Stelle
 *    unbrauchbar, fuer die es diese Festlegung gibt. Der ERSTE Anruf einer
 *    Adresse steht sofort da; die Wiederholung wird gezaehlt, nicht
 *    geschrieben.
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

/**
 * Antworten und aufhoeren.
 *
 * $bremse setzt die Zeile unter die Stundenbremse - fuer alles, was ein
 * Unbefugter beliebig oft ausloesen kann. Der Merker haengt am GRUND und
 * nicht an der Adresse: ein Merker je Adresse waere eine Datei, deren Anzahl
 * der Aufrufer bestimmt, und das ist genau die Sorte Vorrat, die man einem
 * Unbekannten nicht ueberlaesst. Die Adresse steht dafuer in der Zeile.
 */
function bw_ende($code, $zeile, $protokoll = '', $bremse = '')
{
    http_response_code((int) $code);
    echo $zeile . "\n";
    if ($protokoll !== '') {
        $von = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '?';
        $text = 'Endpunkt (' . $von . '): ' . $protokoll;
        if ($bremse !== '') {
            bw_log_wenn_neu('endpunkt_' . $bremse, $text);
        } else {
            bw_log($text);
        }
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
            'abgewiesen - es ist kein Merkwort eingerichtet', 'kein_token');
}
if ($bw_ist === '' || !hash_equals($bw_soll, $bw_ist)) {
    bw_ende(403, ($bw_selftest ? 'SELFTEST' : 'BW') . ';OK=0;ERR=TOKEN',
            'abgewiesen - falsches oder fehlendes Merkwort', 'falsches_token');
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
       schickt, meint ihn.
     *
     * DER HAUPTSCHALTER GILT SEHR WOHL. Bis 0.9.12 stand hier nur die
     * Zielpruefung: ein im Reiter Einstellungen abgeschaltetes Plugin sendete
     * weiter, sobald der virtuelle Ausgang feuerte. Der Kommentar
     * rechtfertigte ausdruecklich nur Fenster und Abstand - "aus" war nie
     * gemeint, es stand nur nirgends. */
    if (empty($bw_cfg['aktiv'])) {
        bw_ende(409, 'BW;OK=0;ERR=ABGESCHALTET', 'jetzt: das Plugin ist abgeschaltet');
    }
    $bw_ziele = bw_ziele($bw_cfg);
    if (!$bw_ziele) {
        bw_ende(409, 'BW;OK=0;ERR=KEIN_ZIEL', 'jetzt: kein Ziel eingerichtet');
    }
    /* DIESELBE SPERRE WIE IM CRON-LAUF. Beide Wege fassen stand.json
       lesen-aendern-schreibend an; ohne die Sperre gehen bei Ueberschneidung
       die Zaehler verloren (die Datei selbst bleibt heil, dafuer sorgt
       bw_json_schreiben). Sie ist nicht blockierend: wer nicht drankommt,
       bekommt eine Antwort und keine Wartezeit. */
    $bw_lock = bw_sperre();
    if ($bw_lock === false) {
        bw_ende(409, 'BW;OK=0;ERR=BESETZT', 'jetzt: ein anderer Lauf ist noch unterwegs');
    }
    $bw_gut = 0;
    $bw_code_fehler = 0;
    $bw_code_gut = 0;
    foreach ($bw_ziele as $bw_z) {
        $bw_r = bw_senden($bw_cfg, $bw_z['uuid'], $bw_z['befehl']);
        if ($bw_r['ok']) {
            $bw_gut++;
            $bw_code_gut = (int) $bw_r['code'];
            continue;
        }
        /* Der Code des ERSTEN Fehlschlags - nicht der des letzten Ziels.
           Sonst steht im Stand HTTP 200 neben einem erhoehten Fehlerzaehler. */
        if ($bw_code_fehler === 0) { $bw_code_fehler = (int) $bw_r['code']; }
    }
    $bw_code = ($bw_gut === count($bw_ziele)) ? $bw_code_gut : $bw_code_fehler;
    $bw_st = bw_stand_lesen();
    $bw_st['letzte'] = time();
    if ($bw_gut > 0) { $bw_st['letzte_ok'] = time(); }
    $bw_st['gesendet'] = (isset($bw_st['gesendet']) ? (int) $bw_st['gesendet'] : 0) + 1;
    $bw_st['fehler'] = ($bw_gut === count($bw_ziele))
        ? 0 : ((isset($bw_st['fehler']) ? (int) $bw_st['fehler'] : 0) + 1);
    $bw_st['code'] = $bw_code;
    bw_stand_schreiben($bw_st);
    /* takt = false: der Endpunkt sagt etwas ueber den Erfolg DIESES Befehls
       und nichts darueber, ob der Fuenfminutenlauf noch geht. Zeitstempel und
       Zaehler bleiben deshalb stehen - siehe bw_lauf_schreiben(). */
    bw_lauf_schreiben($bw_gut === count($bw_ziele), false);
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
    /* DIE VIERTELSTUNDE WIRD ERZWUNGEN, NICHT NUR ZUGESAGT.
     *
     * Ein Aufruf erzeugt bis zu 1 + 3x40 = 121 nacheinander laufende Abrufe
     * an den Miniserver, jeder mit der eingestellten Frist. Der Cron-Lauf hat
     * diese Bremse seit jeher (bw_lauf.php); der Endpunkt hatte sie nicht,
     * obwohl der Kommentar in der Loxone-Vorlage dem Anwender "nicht oefter
     * als alle 15 Minuten" zusagt. Eine Zusage, die niemand einhaelt, ist
     * keine. Die letzte Messung wird zurueckgegeben - sie ist hoechstens eine
     * Viertelstunde alt und damit dasselbe, was der Cron-Lauf liefern
     * wuerde. */
    $bw_st = bw_stand_lesen();
    $bw_letzte_pruefung = isset($bw_st['scharf_ts']) ? (int) $bw_st['scharf_ts'] : 0;
    if (time() - $bw_letzte_pruefung < 900) {
        bw_ende(200, bw_statuszeile($bw_cfg, $bw_st),
                'pruefen: letzte Messung ist ' . (time() - $bw_letzte_pruefung)
                . ' s alt, nicht neu gemessen');
    }
    /* Dieselbe Sperre wie bei jetzt: die Messung haelt einen Webserver-
       Arbeiter lange fest, und der Cron-Lauf misst dasselbe. */
    $bw_lock = bw_sperre();
    if ($bw_lock === false) {
        bw_ende(409, 'BW;OK=0;ERR=BESETZT', 'pruefen: ein anderer Lauf ist noch unterwegs');
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
