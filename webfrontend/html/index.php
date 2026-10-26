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
 * 5. DER LESEZWEIG HAENGT AM HAKEN endpunkt_ein (seit 0.9.31, Gruppe 23,
 *    E-E13): status und json antworten nur mit "HTTP-Lesezweig fuer Loxone
 *    annehmen" (ab Werk aus, nach einem Update an), sonst 403
 *    ERR=ENDPUNKT_AUS - VOR der Merkwortpruefung, damit auch ein falsches
 *    Merkwort nichts verraet. jetzt, pruefen und selftest bleiben hinter dem
 *    Merkwort (der alte Befehlsweg; der Reiter Test misst den Selbsttest).
 *    Neue Anlagen bekommen die Werte ueber MQTT und geben Befehle ueber die
 *    Befehlsbruecke.
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
/* Das Tor des Lesezweigs (Festlegung 5): vor der Merkwortpruefung, nur status und json (ohne aktion = status),
   erkannt am WERT von selftest wie unten (?selftest=0 faellt nicht daran vorbei in den Lesezweig). */
$bw_a0 = bw_par('aktion');
if ($bw_a0 === '') { $bw_a0 = 'status'; }
if (($bw_a0 === 'status' || $bw_a0 === 'json') && bw_par('selftest') !== '1'
        && (int) (isset($bw_cfg['endpunkt_ein']) ? $bw_cfg['endpunkt_ein'] : 0) !== 1) {
    if ($bw_a0 === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    }
    bw_ende(403, ($bw_a0 === 'json' ? '{"OK":0,"ERR":"ENDPUNKT_AUS"}' : 'BW;OK=0;ERR=ENDPUNKT_AUS'),
            'Lesezweig aus (Haken endpunkt_ein)', 'endpunkt_aus');
}
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

/* VOR DEM ERSTEN DURCHGANG HTTP 503 (Regeln/07, "Faellt die Quelle ganz aus,
   liefert der Endpunkt HTTP 503 ohne Daten" - auch vor dem ersten Abruf). Bis
   0.9.21 kam hier 200 mit OK=0 und ZAEHLER=-1; in Loxone sah das aus wie ein
   Messwert. Mit 503 behaelt Loxone die letzten Werte und zeigt den Eingang als
   gestoert. Gilt fuer status und json; jetzt und pruefen loesen etwas aus und
   haengen nicht am Takt. Die Zeile ist gebremst: der Miniserver fragt je
   Minute. */
if (($bw_aktion === 'status' || $bw_aktion === 'json') && bw_lauf_lesen()['ts'] === 0) {
    if ($bw_aktion === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        bw_ende(503, '{"OK":0,"GRUND":"NIE_GELAUFEN"}',
                'json: noch kein Durchgang des Takts - 503', 'nie_gelaufen');
    }
    bw_ende(503, 'BW;OK=0;GRUND=NIE_GELAUFEN',
            'status: noch kein Durchgang des Takts - 503', 'nie_gelaufen');
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
       schickt, meint ihn. DER HAUPTSCHALTER GILT (seit 0.9.13).
     *
     * SEIT 0.9.31 (Gruppe 23, E-E2) EINE Stelle mit dem Knopf und der
     * Befehlsbruecke: bw_aktion_jetzt() - Hauptschalter, Ziele, Sperre
     * lauf.lock, Stand unter der Sperre frisch, Rechnung
     * bw_stand_nach_senden(), Lebenszeichen ohne Takt, MQTT sofort. Die
     * Antworten sind dieselben wie bis 0.9.30. */
    $bw_r = bw_aktion_jetzt($bw_cfg, 'http');
    if ($bw_r['fehler'] === 'ABGESCHALTET') {
        bw_ende(409, 'BW;OK=0;ERR=ABGESCHALTET', 'jetzt: das Plugin ist abgeschaltet');
    }
    if ($bw_r['fehler'] === 'KEIN_ZIEL') {
        bw_ende(409, 'BW;OK=0;ERR=KEIN_ZIEL', 'jetzt: kein Ziel eingerichtet');
    }
    if ($bw_r['fehler'] === 'BESETZT') {
        bw_ende(409, 'BW;OK=0;ERR=BESETZT', 'jetzt: ein anderer Lauf ist noch unterwegs (' . $bw_r['grund'] . ')');
    }
    $bw_st = is_array($bw_r['stand']) ? $bw_r['stand'] : bw_stand_lesen();
    bw_ende($bw_r['ok'] ? 200 : 502, bw_statuszeile($bw_cfg, $bw_st),
            'jetzt: ' . $bw_r['gut'] . ' von ' . $bw_r['n'] . ' Zielen, HTTP ' . (int) (isset($bw_st['code']) ? $bw_st['code'] : 0)
            . ($bw_r['fehler'] === 'FRIST' || strpos($bw_r['grund'], 'Frist') === 0 ? ' - ' . $bw_r['grund'] : ''));
}

if ($bw_aktion === 'pruefen') {
    /* Ab Werk aus - und das wird gesagt, nicht stillschweigend als 0 beantwortet. DIE VIERTELSTUNDE WIRD ERZWUNGEN:
       eine Messung ist hoechstens 900 s alt, sonst kommt die letzte zurueck. Seit 0.9.31 EINE Stelle mit Knopf und
       Befehlsbruecke: bw_aktion_pruefen() (Sperre, Stand frisch, Fehlschlag vermerkt - C3). */
    $bw_r = bw_aktion_pruefen($bw_cfg, 'http');
    if ($bw_r['fehler'] === 'PRUEFEN_AUS') {
        bw_ende(409, 'BW;OK=0;ERR=PRUEFEN_AUS', 'pruefen: abgeschaltet');
    }
    if ($bw_r['fehler'] === 'BESETZT') {
        bw_ende(409, 'BW;OK=0;ERR=BESETZT', 'pruefen: ein anderer Lauf ist noch unterwegs (' . $bw_r['grund'] . ')');
    }
    if (!$bw_r['neu']) {
        bw_ende(200, bw_statuszeile($bw_cfg, $bw_r['stand']), 'pruefen: ' . $bw_r['grund']);
    }
    if (!$bw_r['ok']) {
        bw_ende(502, 'BW;OK=0;ERR=PRUEFEN_MISSLUNGEN', 'pruefen ' . $bw_r['grund']);
    }
    bw_ende(200, bw_statuszeile($bw_cfg, $bw_r['stand']),
            'pruefen: ' . $bw_r['erg']['scharf'] . ' von ' . $bw_r['erg']['gesamt'] . ' Automatiken scharf');
}
