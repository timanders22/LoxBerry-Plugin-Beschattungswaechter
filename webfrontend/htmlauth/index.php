<?php
/**
 * Beschattungswaechter - Bedienoberflaeche
 *
 * Diese Datei ist NUR Oberflaeche. Der Unterbau steht in
 * webfrontend/html/bw_lib.php, der Lauf in bin/bw_lauf.php.
 *
 * (c) Beschattungswaechter Plugin Authors - MIT-Lizenz
 */

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* Der Unterbau liegt im anderen Baum. Wie weit die beiden auseinander liegen,
   haengt davon ab, wie das Plugin gerade liegt - installiert drei Stufen,
   im Archiv zwei. Vorrang hat die Umgebungsvariable von LoxBerry. */
$bw_kandidaten = array();
$bw_html = getenv('LBPHTMLDIR');
if ($bw_html !== false && $bw_html !== '') {
    $bw_kandidaten[] = rtrim($bw_html, '/\\') . '/bw_lib.php';
}
$bw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/'
                 . basename(__DIR__) . '/bw_lib.php';
$bw_kandidaten[] = dirname(__DIR__) . '/html/bw_lib.php';
$bw_geladen = '';
foreach ($bw_kandidaten as $bw_k) {
    if (is_file($bw_k)) { require_once $bw_k; $bw_geladen = $bw_k; break; }
}
if ($bw_geladen === '' || !function_exists('bw_paths')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Beschattungswaechter: der Unterbau wurde nicht gefunden</h2><ul>';
    foreach ($bw_kandidaten as $bw_k) {
        echo '<li><code>' . htmlspecialchars($bw_k, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul><p>Die Datei <code>bw_lib.php</code> gehoert nach '
       . '<code>webfrontend/html/plugins/&lt;ordner&gt;/</code>. Fehlt sie dort, '
       . 'ist die Installation unvollstaendig.</p>';
    exit;
}

/* Der LoxBerry-Rahmen. OHNE dieses require gibt es die Klasse LBWeb nicht,
   class_exists() ist dann immer falsch, und die Seite erscheint ohne Menue.
   Erst loxberry_system.php, dann loxberry_web.php - das zweite baut auf dem
   ersten auf. Der Rahmen wird EINMAL festgestellt und Kopf wie Fuss an
   dieselbe Variable gehaengt: zwei getrennte Abfragen koennen auseinander
   laufen und einen Fuss ohne Kopf ergeben. */
$bw_lb = bw_paths()['lbhome'];
if ($bw_lb !== '' && is_file($bw_lb . '/libs/phplib/loxberry_system.php')) {
    require_once $bw_lb . '/libs/phplib/loxberry_system.php';
    if (is_file($bw_lb . '/libs/phplib/loxberry_web.php')) {
        require_once $bw_lb . '/libs/phplib/loxberry_web.php';
    }
}
$bw_rahmen = class_exists('LBWeb', false);

/* ---------- Reiter: Positivliste, ids und Leiste gehoeren zusammen -------- */
/* Die fuenf Reiter des Hausstandards. MQTT ist IMMER ein eigener Reiter, nie
   ein Unterabschnitt - mit eigenem Formular und eigenem Speicher-Handler:
   ein Sammel-Handler setzt Haken per isset() und wuerde beim Absenden des
   einen Formulars die Werte des anderen stillschweigend nullen. */
$bw_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$bw_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $bw_reiter, true)) {
    $bw_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && in_array('tab-' . $_GET['form'], $bw_reiter, true)) {
    $bw_tab = 'tab-' . $_GET['form'];
}

/* ---------- Eingaben verarbeiten ----------------------------------------- */
$bw_cfg = bw_config();

/* Meldungen und Beanstandungen sind beide LISTEN. Bis 0.9.10 war die eine
   eine Zeichenkette und die andere eine Liste - der Sicherungs-Handler
   schrieb nach $bw_meldungen[], und diese Variable wurde nirgends gelesen.
   Die Erfolgsmeldung des Zurueckspielens erschien deshalb NIE. Der Block
   stammte woertlich aus MG iSmart, wo die Ablage eine Liste ist; beim
   Uebernehmen wurde sie nicht mitgemessen. */
$bw_meldungen = array();
$bw_fehler = array();

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
$bw_wache = bw_wachposten();
if ($bw_wache !== '') {
    $bw_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($bw_reiter_merk !== null) {
        $_POST['activetab'] = $bw_reiter_merk;
    }
    $bw_fehler[] = $bw_wache;
}

/* Das Merkwort entsteht beim ERSTEN Aufruf der Oberflaeche - hier, im
   angemeldeten Bereich, und nicht im Endpunkt. Wer es bewusst geleert hat,
   bekommt es NICHT zurueck: bw_token() unterscheidet "Schluessel fehlt" von
   "Schluessel da, leer". */
bw_token(true);
$bw_cfg = bw_config();

/* ---------------- Einstellungen speichern ----------------
 *
 * Beanstandet wird je Feld, und was sich nicht beanstanden laesst, wird
 * gespeichert. Bis 0.9.10 verhinderte eine unbrauchbare Kennung das
 * Speichern ALLER Felder - der Benutzer tippte dann Zeitfenster und Abstand
 * noch einmal, wegen eines Feldes, das er ohnehin gleich korrigiert haette.
 * Blockieren darf nur, was das Speichern technisch unmoeglich macht.
 *
 * Geprueft wird mit bw_wert_pruefen() - derselben Positivliste, gegen die
 * auch die Sicherungsdatei und die Konfigurationsdatei gehalten werden. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['speichern'])) {
    $bw_neu = $bw_cfg;
    $bw_neu['aktiv'] = empty($_POST['aktiv']) ? 0 : 1;

    /* Der Miniserver wird ueber seinen SCHLUESSEL gespeichert, nicht ueber
       seine Stellung - siehe bw_vorgaben(). Die Stellung wird mitgefuehrt,
       damit eine aeltere Fassung des Plugins die Datei weiter lesen kann. */
    $bw_wahl = isset($_POST['ms_nr']) ? trim((string) $_POST['ms_nr']) : '';
    if (bw_wert_pruefen('ms_nr', $bw_wahl)) {
        $bw_neu['ms_nr'] = $bw_wahl;
        foreach (bw_miniserver() as $bw_i => $bw_m) {
            if ($bw_m['nr'] === $bw_wahl) { $bw_neu['ms'] = $bw_i; break; }
        }
    } elseif ($bw_wahl !== '') {
        $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'),
                               bw_e(bw_t('TEXT.L_MS')), bw_e(bw_kurz($bw_wahl)));
    }

    $bw_felderliste = array('uuid', 'befehl', 'von', 'bis', 'abstand');
    for ($bw_i = 2; $bw_i <= 6; $bw_i++) {
        $bw_felderliste[] = 'uuid' . $bw_i;
        $bw_felderliste[] = 'befehl' . $bw_i;
    }
    foreach ($bw_felderliste as $bw_f) {
        $bw_w = isset($_POST[$bw_f]) ? $_POST[$bw_f] : '';
        if (bw_wert_pruefen($bw_f, $bw_w)) {
            $bw_neu[$bw_f] = trim((string) $bw_w);
        } else {
            /* Die Beschriftung eines nummerierten Ziels kommt aus dem
               Grundschluessel plus der Nummer - nicht aus einem eigenen
               Sprachschluessel je Zeile. */
            $bw_grund = preg_replace('/[0-9]+$/', '', $bw_f);
            $bw_bez = bw_t('TEXT.L_' . strtoupper($bw_grund));
            if ($bw_grund === 'uuid' || $bw_grund === 'befehl') {
                $bw_nr = substr($bw_f, strlen($bw_grund));
                $bw_bez .= ' (' . bw_t('TEXT.ZIEL') . ' ' . ($bw_nr !== '' ? $bw_nr : '1') . ')';
            }
            $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'),
                                   bw_e($bw_bez), bw_e(bw_kurz($bw_w)));
        }
    }
    $bw_neu['pruefen_ein'] = empty($_POST['pruefen_ein']) ? 0 : 1;
    if (bw_config_speichern($bw_neu)) {
        $bw_meldungen[] = $bw_fehler
            ? bw_t('TEXT.GESPEICHERT_TEILWEISE') : bw_t('TEXT.GESPEICHERT');
        $bw_cfg = bw_config();
    } else {
        $bw_fehler[] = bw_t('TEXT.SICH_SCHREIBFEHLER');
    }
}

/* ---------------- MQTT: eigenes Formular, eigener Handler ----------------
 *
 * Der Einstellungs-Handler fasst die MQTT-Werte NICHT mehr an. Stuende dort
 * weiter isset($_POST['mqtt_ein']) ? 1 : 0, schaltete jedes Speichern der
 * Einstellungen MQTT stillschweigend ab. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['speichern_mqtt'])) {
    $bw_neu = $bw_cfg;
    $bw_neu['mqtt_ein'] = empty($_POST['mqtt_ein']) ? 0 : 1;
    $bw_th = isset($_POST['mqtt_thema']) ? trim((string) $_POST['mqtt_thema']) : '';
    if (bw_wert_pruefen('mqtt_thema', $bw_th)) {
        $bw_neu['mqtt_thema'] = $bw_th;
    } else {
        $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'),
                               bw_e(bw_t('TEXT.L_THEMA')), bw_e(bw_kurz($bw_th)));
    }
    if (bw_config_speichern($bw_neu)) {
        $bw_meldungen[] = $bw_fehler
            ? bw_t('TEXT.GESPEICHERT_TEILWEISE') : bw_t('TEXT.GESPEICHERT');
        $bw_cfg = bw_config();
    } else {
        $bw_fehler[] = bw_t('TEXT.SICH_SCHREIBFEHLER');
    }
}

/* ---------------- Ein neues Merkwort ----------------
 *
 * Der Knopf ist ORANGE, nicht grau: er liest nicht, er macht jede Adresse
 * ungueltig, die im Miniserver steht. Der Warnhinweis steht daneben, nicht
 * erst in der Antwort nach dem Klick. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $bw_neu = $bw_cfg;
    $bw_neu['aktionstoken'] = bw_token_neu();
    if (bw_config_speichern($bw_neu)) {
        $bw_cfg = bw_config();
        $bw_meldungen[] = bw_t('TEXT.TOKEN_NEU_OK');
        bw_log('Ein neues Merkwort fuer den Endpunkt wurde erzeugt.');
    } else {
        $bw_fehler[] = bw_t('TEXT.SICH_SCHREIBFEHLER');
    }
}

/* ---------------- Die Importdatei fuer Loxone Config ----------------
 *
 * Ein Download-Handler steht IMMER vor lbheader(). Unter dem PHP-CLI ist der
 * Fehler unsichtbar - dort ist header() wirkungslos -, und auf der Anlage
 * bekommt der Bediener eine Seite mit angehaengtem XML statt einer Datei. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    $bw_art = (isset($_POST['vorlage']) && is_string($_POST['vorlage'])
               && $_POST['vorlage'] === 'out') ? 'out' : 'in';
    list($bw_dname, $bw_inhalt) = bw_vorlage($bw_art, $bw_cfg);
    /* Wohlgeformt oder nicht - das ist nicht verhandelbar. Der Anwender
       merkt eine kaputte Vorlage sonst erst in Loxone Config, und dort sucht
       er den Fehler bei sich. */
    $bw_frueher = libxml_use_internal_errors(true);
    $bw_wohl = simplexml_load_string($bw_inhalt) !== false;
    libxml_clear_errors();
    libxml_use_internal_errors($bw_frueher);
    if (!$bw_wohl) {
        $bw_fehler[] = bw_t('TEXT.VORLAGE_KAPUTT');
    } else {
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $bw_dname . '"');
        echo $bw_inhalt;
        exit;
    }
}

/* ---------------- Den Rest der 0.9.0 wegraeumen ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rest_weg'])) {
    $bw_rest0 = ($bw_lb !== '') ? $bw_lb . '/system/cron/cron.15min/' . bw_paths()['plugin'] : '';
    if ($bw_rest0 === '' || !file_exists($bw_rest0)) {
        $bw_fehler[] = bw_t('TEXT.REST_KEINER');
    } else {
        /* Der Rest kann BEIDES sein: eine Datei (so war es gedacht) oder
           ein Verzeichnis (so hat die 0.9.0 es angelegt - und genau daran
           lief das Plugin am 24.08.2026 eine Stunde lang leer). Nur den
           einen Fall zu behandeln hiesse, den haeufigeren stehen zu lassen
           und trotzdem Erfolg zu melden.
           Im Verzeichnisfall werden GENAU die Namen entfernt, die dieses
           Plugin dort abgelegt hat, und danach das Verzeichnis nur, wenn es
           leer ist: auf einem fremden Rechner loescht man nicht mehr, als
           man hingelegt hat. */
        if (is_dir($bw_rest0)) {
            foreach (array(bw_paths()['plugin'], 'cron.15min', 'cron.05min') as $bw_n) {
                @unlink($bw_rest0 . '/' . $bw_n);
            }
            @rmdir($bw_rest0);
        } else {
            @unlink($bw_rest0);
        }
        if (file_exists($bw_rest0)) {
            $bw_fehler[] = sprintf(bw_t('TEXT.REST_BLEIBT'), bw_e($bw_rest0));
        } else {
            $bw_meldungen[] = bw_t('TEXT.REST_FORT');
            bw_log('Rest der Fassung 0.9.0 aus cron.15min entfernt.');
        }
    }
}

/* ---------------- Die Automatiken jetzt zaehlen ---------------- */
$bw_zaehlung = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['zaehlen'])) {
    list($bw_zok, $bw_zmeldung, $bw_zerg) = bw_automatiken($bw_cfg);
    if ($bw_zok) {
        $bw_zaehlung = $bw_zerg;
        $bw_st = bw_stand_lesen();
        $bw_st['scharf'] = (int) $bw_zerg['scharf'];
        $bw_st['automatiken'] = (int) $bw_zerg['gesamt'];
        $bw_st['scharf_ts'] = time();
        bw_stand_schreiben($bw_st);
    } else {
        $bw_fehler[] = $bw_zmeldung;
    }
}

/* ---------------- Senden: echt oder als Probe ---------------- */
$bw_ergebnisse = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && (isset($_POST['jetzt']) || isset($_POST['trocken']))) {
    $bw_ist_probe = isset($_POST['trocken']);
    if ($bw_ist_probe) { bw_trocken(true); }
    try {
        foreach (bw_ziele($bw_cfg) as $bw_z) {
            $bw_r = bw_senden($bw_cfg, $bw_z['uuid'], $bw_z['befehl']);
            $bw_r['nr'] = $bw_z['nr'];
            $bw_r['befehl'] = $bw_z['befehl'];
            $bw_r['uuid'] = $bw_z['uuid'];
            $bw_ergebnisse[] = $bw_r;
        }
    } finally {
        /* Bliebe der Schalter stehen, taete der naechste echte Befehl im
           selben Prozess still nichts. */
        bw_trocken(false);
    }
    if (!$bw_ergebnisse) {
        $bw_fehler[] = bw_t('TEXT.KEIN_ZIEL');
    } elseif (!$bw_ist_probe) {
        $bw_gut = 0;
        foreach ($bw_ergebnisse as $bw_r) { if ($bw_r['ok']) { $bw_gut++; } }
        $bw_st = bw_stand_lesen();
        $bw_st['letzte'] = time();
        if ($bw_gut > 0) { $bw_st['letzte_ok'] = time(); }
        $bw_st['gesendet'] = (isset($bw_st['gesendet']) ? (int) $bw_st['gesendet'] : 0) + 1;
        $bw_st['fehler'] = ($bw_gut === count($bw_ergebnisse)) ? 0
            : ((isset($bw_st['fehler']) ? (int) $bw_st['fehler'] : 0) + 1);
        $bw_st['code'] = (int) $bw_ergebnisse[count($bw_ergebnisse) - 1]['code'];
        bw_stand_schreiben($bw_st);
        bw_lauf_schreiben($bw_gut === count($bw_ergebnisse));
        bw_mqtt_publish($bw_cfg, $bw_st);
        bw_log('von Hand ausgeloest: ' . $bw_gut . ' von ' . count($bw_ergebnisse)
             . ' Ziel(en) angenommen');
    }
}

$bw_stand = bw_stand_lesen();
$bw_ms = bw_miniserver();

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration samt lesbarem Kopf. Ein
 * Aktionstoken ist NICHT darunter, weil dieses Plugin keinen hat: es fuehrt
 * keinen Endpunkt im unangemeldeten Bereich, und die Zugangsdaten des
 * Miniservers stehen in der zentralen LoxBerry-Konfiguration. Die Datei
 * traegt also Einstellungen und kein Geheimnis - und genau das sagt der
 * Hinweis am Knopf. Bis 0.9.10 behauptete er das Gegenteil, zwei Absaetze
 * unter einem Satz, der es richtig sagte. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bw_sichern'])) {
    $bw_js = json_encode(bw_sicherung_bauen(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($bw_js) && $bw_js !== '') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="beschattungswaechter_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $bw_js;
        exit;
    }
    $bw_fehler[] = bw_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bw_zurueck'])) {
    if (!isset($_FILES['bw_sicherung']) || !is_array($_FILES['bw_sicherung'])
        || !isset($_FILES['bw_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['bw_sicherung']['tmp_name'])) {
        $bw_fehler[] = bw_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['bw_sicherung']['size'] > 262144) {
        $bw_fehler[] = bw_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($bw_neu, $bw_mangel, $bw_n) = bw_sicherung_lesen(
            (string) @file_get_contents($_FILES['bw_sicherung']['tmp_name']));
        if ($bw_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $bw_fehler[] = bw_t('TEXT.SICH_ABGELEHNT') . ' '
                            . implode(' ', $bw_mangel);
        } elseif (bw_config_speichern($bw_neu)) {
            $bw_meldungen[] = sprintf(bw_t('TEXT.SICH_UEBERNOMMEN'), $bw_n);
            /* Die Felder darunter zeigen sonst weiter den alten Stand: der
               Bediener sieht keine Aenderung und drueckt noch einmal. */
            $bw_cfg = bw_config();
            $bw_meldungen[] = bw_t('TEXT.SICH_DANACH');
        } else {
            $bw_fehler[] = bw_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}


if ($bw_rahmen) {
    LBWeb::lbheader(bw_t('ALLGEMEIN.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard - wortgetreu aus VORLAGE_hausstandard.css.html */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap input[type=file],
.sm-wrap select, .sm-wrap textarea {
  width: 100%; max-width: 520px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
  font-size: 0.95em; box-sizing: border-box; }
.sm-wrap table input[type=text], .sm-wrap table select { max-width: none; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
/* Ein Auswahlfeld bringt seinen Pfeil SELBST mit. Die Rahmen-CSS des
   LoxBerry (jQuery Mobile) setzt appearance: none, und damit verschwindet
   der Pfeil, den sonst der Browser zeichnet - das Feld sieht aus wie ein
   Textfeld, und wer nicht hineinklickt, erfaehrt nie, dass eine Auswahl
   dahintersteht. Zweimal in dieser Reihe von einem Menschen gefunden, nie
   von einem Werkzeug: rendern.py sieht HTML, kein Bild.
   Die Raute in der SVG-Adresse wird als %23 geschrieben - eine rohe Raute
   beendet den CSS-Wert. */
.sm-wrap select.sm-auswahl {
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%23546e7a' stroke-width='2'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center;
  padding-right: 32px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Jede Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt in
   <div class="sm-breit">. Anlass: bei der Bewaesserung stand die letzte
   Spalte auf einem schmalen Bildschirm ausserhalb - nicht unbequem,
   UNERREICHBAR, weil .sm-wrap eine max-width ohne Ueberlauf hat. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 620px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-kachel span { font-size: 0.82em; color: #666; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1 1 220px; }
.sm-grau { color: #999; font-style: italic; }
</style>

<div class="sm-wrap">

<?php
/* Meldungen und Beanstandungen gehen ROH hinaus. Sie tragen Auszeichnung
   (<b>...</b>), und die eingesetzten Argumente sind an der Einsetzstelle
   bereits maskiert. Bis 0.9.10 lief beides ein zweites Mal durch bw_e() -
   auf dem Bildschirm stand dann woertlich "&lt;b&gt;nicht&lt;/b&gt;". Das ist
   der Befund mit vierzig Fundstellen in dreizehn Plugins, hier an der
   Ausgabestelle statt in der Sprachdatei; deshalb hat ihn die Spalte msk
   von hausstandard_pruefen.py nicht gesehen. */
foreach ($bw_meldungen as $bw_m) { echo '<div class="sm-hinweis">' . $bw_m . '</div>'; }
foreach ($bw_fehler as $bw_fm)   { echo '<div class="sm-fehler">' . $bw_fm . '</div>'; }
?>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN da, nicht in einer Schleife
     erzeugt. Umgeschaltet wird ueber den Server, damit jeder Reiter
     verlinkbar und die Seite ohne Skript bedienbar bleibt. Ob Leiste,
     Bereiche und Positivliste dieselben Namen fuehren, zaehlt der Reiter
     Test nach. -->
<div class="sm-tabs">
  <a class="sm-tab<?= $bw_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
     href="index.php?form=settings"><?php echo bw_t('REITER.EINSTELLUNGEN'); ?></a>
  <a class="sm-tab<?= $bw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
     href="index.php?form=mqtt">MQTT</a>
  <a class="sm-tab<?= $bw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
     href="index.php?form=loxone"><?php echo bw_t('REITER.LOXONE'); ?></a>
  <a class="sm-tab<?= $bw_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
     href="index.php?form=test"><?php echo bw_t('REITER.TEST'); ?></a>
  <a class="sm-tab<?= $bw_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
     href="index.php?form=log"><?php echo bw_t('REITER.LOGDATEIEN'); ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<h2><?php echo bw_t('TEXT.H_EINSTELLUNGEN'); ?></h2>

<div class="sm-step"><?php echo bw_t('TEXT.WARUM'); ?></div>

<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="aktiv" value="1"<?= !empty($bw_cfg['aktiv']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_AKTIV'); ?></label>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_AKTIV'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_MS'); ?></label>
  <select data-role="none" class="sm-auswahl" name="ms_nr">
<?php
$bw_gewaehlt = bw_miniserver_gewaehlt($bw_cfg, $bw_ms);
foreach ($bw_ms as $bw_m2) { ?>
    <option value="<?= bw_e($bw_m2['nr']) ?>"<?= ($bw_gewaehlt !== null && $bw_gewaehlt['nr'] === $bw_m2['nr']) ? ' selected' : '' ?>><?= bw_e($bw_m2['name'] . ' (' . $bw_m2['adresse'] . ')') ?></option>
<?php } ?>
<?php if (!$bw_ms) { ?><option value=""><?php echo bw_t('TEXT.KEIN_MS'); ?></option><?php } ?>
  </select>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_MS'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_UUID'); ?></label>
  <input data-role="none" type="text" name="uuid" value="<?= bw_e($bw_cfg['uuid']) ?>">
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_UUID'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_BEFEHL'); ?></label>
  <input data-role="none" type="text" name="befehl" value="<?= bw_e($bw_cfg['befehl']) ?>">
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_BEFEHL'); ?></p>
</div>

<h3><?php echo bw_t('TEXT.H_ZIELE'); ?></h3>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_ZIELE_ERKL'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?php echo bw_t('TEXT.L_UUID'); ?></th><th><?php echo bw_t('TEXT.L_BEFEHL'); ?></th></tr>
<?php for ($bw_i = 2; $bw_i <= 6; $bw_i++) {
    $bw_ku = 'uuid' . $bw_i; $bw_kb = 'befehl' . $bw_i; ?>
  <tr><td><?= (int) $bw_i ?></td>
      <td><input data-role="none" type="text" name="<?= $bw_ku ?>" value="<?= bw_e(isset($bw_cfg[$bw_ku]) ? $bw_cfg[$bw_ku] : '') ?>"></td>
      <td><input data-role="none" type="text" name="<?= $bw_kb ?>" value="<?= bw_e(isset($bw_cfg[$bw_kb]) ? $bw_cfg[$bw_kb] : '') ?>"></td></tr>
<?php } ?>
</table>
</div>

<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="pruefen_ein" value="1"<?= !empty($bw_cfg['pruefen_ein']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_PRUEFEN'); ?></label>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_PRUEFEN'); ?></p>
</div>

<div class="sm-row">
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_VON'); ?></label>
    <input data-role="none" type="text" name="von" value="<?= bw_e($bw_cfg['von']) ?>" placeholder="06:00">
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_BIS'); ?></label>
    <input data-role="none" type="text" name="bis" value="<?= bw_e($bw_cfg['bis']) ?>" placeholder="21:00">
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_ABSTAND'); ?></label>
    <input data-role="none" type="text" name="abstand" value="<?= (int) $bw_cfg['abstand'] ?>">
  </div>
</div>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_FENSTER'); ?></p>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?php echo bw_t('TEXT.SPEICHERN'); ?></button>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span> <span><i class="sm-punkt sm-b-lesen"></i> <?php echo bw_t('LEGENDE.LESEN'); ?></span></div>
</form>

<h2><?= bw_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= bw_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-hinweis"><?= bw_t('TEXT.SICH_INHALT') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt.
       accept=".json" ist ein Hinweis fuer den Dateidialog und KEINE
       Pruefung - geprueft wird serverseitig. -->
  <form action="index.php" method="post">
    <?php echo bw_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="bw_sichern" value="1"><?= bw_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo bw_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="bw_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="bw_zurueck" value="1"><?= bw_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<div class="sm-step"><?php echo bw_t('MQTT.WARUM'); ?></div>
<?php
/* Der Gateway ist KEIN Plugin, sondern seit LoxBerry 3 Bestandteil des
   Systems - er wird nie als freiwillig dargestellt. Ist die Fassung nicht
   lesbar, steht dort 0 und NICHT 1: eine geratene Fassung waere fuer die
   Haelfte der Anlagen die falsche Anleitung. */
$bw_gw = bw_mqtt_gateway_info();
$bw_gwf = ($bw_gw === null) ? 0 : (int) $bw_gw['fassung'];
if ($bw_gw === null) { ?>
<div class="sm-warnung"><?php echo bw_t('MQTT.KEIN_GENERAL'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
  <tr><th><?php echo bw_t('MQTT.EIGENSCHAFT'); ?></th><th><?php echo bw_t('TEXT.GEMESSEN'); ?></th></tr>
  <tr><td><?php echo bw_t('MQTT.AUTOSTART'); ?></td>
      <td><?= $bw_gw['autostart'] ? '<span class="sm-an">' . bw_e(bw_t('TEXT.JA')) . '</span>'
                                  : '<span class="sm-aus">' . bw_e(bw_t('TEXT.NEIN')) . '</span>' ?></td></tr>
  <tr><td><?php echo bw_t('MQTT.FASSUNG'); ?></td>
      <td class="sm-mono"><?= $bw_gwf > 0 ? (int) $bw_gwf : bw_e(bw_t('MQTT.UNBEKANNT')) ?></td></tr>
  <tr><td><?php echo bw_t('MQTT.UDPPORT'); ?></td>
      <td class="sm-mono"><?= (int) $bw_gw['udpport'] ?></td></tr>
</table>
<?php if (!$bw_gw['autostart']) { ?>
<div class="sm-warnung"><?php echo bw_t('MQTT.AUTOSTART_WARNUNG'); ?></div>
<?php } ?>
<?php } ?>

<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
  <div class="sm-feld">
    <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($bw_cfg['mqtt_ein']) ? ' checked' : '' ?>>
      <?php echo bw_t('MQTT.L_EIN'); ?></label>
    <p class="sm-hilfe"><?php echo bw_t('MQTT.H_EIN'); ?></p>
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_THEMA'); ?></label>
    <input data-role="none" type="text" name="mqtt_thema" value="<?= bw_e($bw_cfg['mqtt_thema']) ?>">
    <p class="sm-hilfe"><?php echo bw_t('MQTT.H_THEMA'); ?></p>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_mqtt" value="1"><?php echo bw_t('TEXT.SPEICHERN'); ?></button>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span></div>
</form>

<h3><?php echo bw_t('MQTT.H_ABO'); ?></h3>
<p><span class="sm-mono"><?= bw_e(bw_mqtt_praefix($bw_cfg['mqtt_thema'])) ?>/#</span></p>
<?php
/* Der Pflichtsatz haengt an der FASSUNG. Unter V2 schaltet der Kern die
   Eintragknoepfe ab - ein pauschaler V1-Satz schickte jeden V2-Anwender zu
   einem Eingabefeld, das es dort nicht mehr gibt. */
if ($bw_gwf >= 2) { ?>
<div class="sm-hinweis"><?php echo bw_t('MQTT.ABO_V2'); ?></div>
<?php } elseif ($bw_gwf === 1) { ?>
<div class="sm-warnung"><?php echo bw_t('MQTT.ABO_PFLICHT'); ?></div>
<?php } else { ?>
<div class="sm-warnung"><?php echo bw_t('MQTT.ABO_PFLICHT'); ?></div>
<p class="sm-hilfe"><?php echo bw_t('MQTT.ABO_V2'); ?></p>
<?php } ?>

<h3><?php echo bw_t('MQTT.H_THEMEN'); ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('MQTT.THEMA'); ?></th><th><?php echo bw_t('MQTT.BEDEUTUNG'); ?></th></tr>
<?php foreach (bw_mqtt_themen($bw_cfg) as $bw_th2 => $bw_bez) { ?>
  <tr><td class="sm-mono"><?= bw_e($bw_th2) ?></td><td><?= bw_e(bw_t($bw_bez)) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?php echo bw_t('MQTT.H_LEBEN'); ?></div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo bw_t('LOX.H'); ?></h2>
<div class="sm-hinweis"><?php echo bw_t('LOX.ERKL'); ?></div>

<div class="sm-step"><b>1. <?php echo bw_t('LOX.S1_T'); ?></b><br><?php echo bw_t('LOX.S1'); ?></div>

<div class="sm-step"><b>2. <?php echo bw_t('LOX.S2_T'); ?></b><br><?php echo bw_t('LOX.S2'); ?>
<table class="sm-tbl">
  <tr><td><?php echo bw_t('LOX.ADRESSE'); ?></td>
      <td class="sm-mono">http://<?= bw_e(bw_wirtsname()) ?><?php
        $bw_par2 = array();
        if ($bw_cfg['aktionstoken'] !== '') { $bw_par2['token'] = (string) $bw_cfg['aktionstoken']; }
        $bw_par2['aktion'] = 'status';
        echo bw_e(bw_endpunkt_pfad($bw_par2));
      ?></td></tr>
  <tr><td><?php echo bw_t('LOX.MERKWORT'); ?></td>
      <td class="sm-mono"><?= bw_e($bw_cfg['aktionstoken'] !== '' ? $bw_cfg['aktionstoken'] : bw_t('LOX.KEIN_TOKEN')) ?></td></tr>
</table>
<div class="sm-warnung"><?php echo bw_t('LOX.MERKWORT_WARNUNG'); ?></div>
<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo bw_t('LOX.K_TOKEN'); ?></button>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span></div>
</form>
</div>

<div class="sm-step"><b>3. <?php echo bw_t('LOX.S3_T'); ?></b><br><?php echo bw_t('LOX.S3'); ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('LOX.FELD'); ?></th><th><?php echo bw_t('LOX.CHECK'); ?></th><th><?php echo bw_t('LOX.EINHEIT'); ?></th><th><?php echo bw_t('LOX.BEDEUTUNG'); ?></th></tr>
<?php
/* Der Suchtext kommt aus bw_check() - EINE Funktion, dieselbe, die den
   Endpunkt beschriftet. Als Fliesstext in der Sprachdatei laeuft er beim
   naechsten neuen Feld auseinander, und abgeschrieben wird die Tabelle,
   nicht der Quelltext. */
foreach (bw_felder() as $bw_fn => $bw_fi) { ?>
  <tr><td class="sm-mono">BW <?= bw_e($bw_fn) ?></td>
      <td class="sm-mono"><?= bw_e(bw_check($bw_fn)) ?></td>
      <td><?= bw_e($bw_fi['einheit']) ?></td>
      <td><?= bw_e(bw_t($bw_fi['bez'])) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?php echo bw_t('LOX.CHECK_HILFE'); ?></p>
</div>

<div class="sm-step"><b>4. <?php echo bw_t('LOX.S4_T'); ?></b><br><?php echo bw_t('LOX.S4'); ?></div>

<div class="sm-step"><b>5. <?php echo bw_t('LOX.S5_T'); ?></b><br><?php echo bw_t('LOX.S5'); ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?php echo bw_t('LOX.BAUSTEIN'); ?></th><th><?php echo bw_t('LOX.NAME'); ?></th><th><?php echo bw_t('LOX.PARAMETER'); ?></th><th><?php echo bw_t('LOX.EINGANG'); ?></th></tr>
<?php
$bw_bausteine = array(
    array('LOX.B1_TYP', 'BW Waechter tot', 'LOX.B1_P', 'LOX.B1_E'),
    array('LOX.B2_TYP', 'BW Waechter gestoert', 'LOX.B2_P', 'LOX.B2_E'),
    array('LOX.B3_TYP', 'BW Automatik abgeschaltet', 'LOX.B3_P', 'LOX.B3_E'),
    array('LOX.B4_TYP', 'BW Meldung', 'LOX.B4_P', 'LOX.B4_E'),
);
$bw_nr2 = 0;
foreach ($bw_bausteine as $bw_bs) { $bw_nr2++; ?>
  <tr><td><?= (int) $bw_nr2 ?></td><td><?= bw_e(bw_t($bw_bs[0])) ?></td>
      <td class="sm-mono"><?= bw_e($bw_bs[1]) ?></td>
      <td><?= bw_e(bw_t($bw_bs[2])) ?></td><td><?= bw_e(bw_t($bw_bs[3])) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<div class="sm-step"><b>6. <?php echo bw_t('LOX.S6_T'); ?></b><br><?php echo bw_t('LOX.S6'); ?>
<div class="sm-warnung"><?php echo bw_t('LOX.IMPORT_WARNUNG'); ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?php echo bw_t('LEGENDE.TECHNIK'); ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo bw_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="in"><?php echo bw_t('LOX.K_VORLAGE_IN'); ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo bw_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="out"><?php echo bw_t('LOX.K_VORLAGE_OUT'); ?></button>
  </form>
</div>
<p class="sm-hilfe"><?php echo bw_t('LOX.IMPORTWEG'); ?></p>
</div>

<div class="sm-step"><b>7. <?php echo bw_t('LOX.S7_T'); ?></b><br><?php echo bw_t('LOX.S7'); ?></div>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo bw_t('TEXT.H_TEST'); ?></h2>

<div class="sm-kacheln">
  <div class="sm-kachel"><b><?= empty($bw_stand['letzte']) ? '&mdash;' : bw_e(date('H:i:s', (int) $bw_stand['letzte'])) ?></b><span><?php echo bw_t('TEXT.K_LETZTE'); ?></span></div>
  <div class="sm-kachel"><b><?= (int) (isset($bw_stand['gesendet']) ? $bw_stand['gesendet'] : 0) ?></b><span><?php echo bw_t('TEXT.K_GESENDET'); ?></span></div>
  <div class="sm-kachel"><b><?= (int) (isset($bw_stand['fehler']) ? $bw_stand['fehler'] : 0) ?></b><span><?php echo bw_t('TEXT.K_FEHLER'); ?></span></div>
  <div class="sm-kachel"><b><?= bw_im_fenster($bw_cfg) ? bw_t('TEXT.JA') : bw_t('TEXT.NEIN') ?></b><span><?php echo bw_t('TEXT.K_FENSTER'); ?></span></div>
</div>

<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-technik"></i> <?php echo bw_t('LEGENDE.TECHNIK'); ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="trocken" value="1"><?php echo bw_t('TEXT.TROCKEN'); ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="jetzt" value="1"><?php echo bw_t('TEXT.JETZT'); ?></button>
</div>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_JETZT'); ?></p>
</form>

<?php if ($bw_ergebnisse) { ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('TEXT.ZIEL'); ?></th><th><?php echo bw_t('TEXT.ERGEBNIS'); ?></th><th>HTTP</th><th><?php echo bw_t('TEXT.ANTWORT'); ?></th><th>URL</th></tr>
<?php foreach ($bw_ergebnisse as $bw_r) { ?>
  <tr><td><?= (int) $bw_r['nr'] ?> &middot; <span class="sm-mono"><?= bw_e($bw_r['befehl']) ?></span></td>
      <td><?php
        if (!empty($bw_r['probe'])) { echo '<span class="sm-grau">' . bw_e(bw_t('TEXT.NUR_PROBE')) . '</span>'; }
        elseif ($bw_r['ok']) { echo '<span class="sm-an">' . bw_e(bw_t('TEXT.GESENDET')) . '</span>'; }
        else { echo '<span class="sm-aus">' . bw_e(bw_t('TEXT.FEHLGESCHLAGEN')) . '</span>'; }
      ?></td>
      <td class="sm-mono"><?= (int) $bw_r['code'] ?></td>
      <td class="sm-mono"><?= bw_e(substr((string) $bw_r['text'], 0, 120)) ?></td>
      <td class="sm-mono"><?= bw_e($bw_r['url']) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h3><?php echo bw_t('TEXT.H_ZAEHLEN'); ?></h3>
<div class="sm-hinweis"><?php echo bw_t('TEXT.H_ZAEHLEN_ERKL'); ?></div>
<?php if (empty($bw_cfg['pruefen_ein'])) { ?>
<div class="sm-warnung"><?php echo bw_t('TEXT.ZAEHLEN_AUS'); ?></div>
<?php } else { ?>
<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="zaehlen" value="1"><?php echo bw_t('TEXT.ZAEHLEN'); ?></button>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?php echo bw_t('LEGENDE.LESEN'); ?></span></div>
</form>
<?php } ?>
<?php if ($bw_zaehlung !== null) { ?>
<p class="sm-hilfe"><b><?= sprintf(bw_e(bw_t('TEXT.ZAEHLUNG')), (int) $bw_zaehlung['scharf'], (int) $bw_zaehlung['gesamt']) ?></b></p>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('TEXT.BAUSTEIN'); ?></th><th><?php echo bw_t('TEXT.RAUM'); ?></th><th>autoActive</th><th><?php echo bw_t('TEXT.GRUND'); ?></th></tr>
<?php foreach ($bw_zaehlung['zeilen'] as $bw_zz) { ?>
  <tr><td><?= bw_e($bw_zz['name']) ?></td><td><?= bw_e($bw_zz['raum']) ?></td>
      <td><?php
        if ($bw_zz['an'] === 1) { echo '<span class="sm-an">1</span>'; }
        elseif ($bw_zz['an'] === 0) { echo '<span class="sm-aus">0</span>'; }
        else { echo '<span class="sm-grau">?</span>'; }
      ?></td>
      <td><?= bw_e($bw_zz['grund']) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h3><?php echo bw_t('TEXT.H_SELBST'); ?></h3>
<?php
/* Jede Zeile hat DREI Ausgaenge, nicht zwei: ja, nein, und "ich konnte hier
   nichts messen". Der dritte gehoert benannt und darf in keiner
   Zusammenfassung als bestanden zaehlen - ein rotes Kreuz, das nichts
   bedeutet, ist schlimmer als keine Pruefung, denn man sucht dann dort.
   Am Ende steht deshalb, wie viele Striche in der Liste stehen. */
$bw_selbst = array();
function bw_zeile(&$liste, $was, $ok, $wie)
{
    $liste[] = array('was' => $was, 'ok' => (int) $ok, 'wie' => (string) $wie);
}

/* Gezaehlt wird in DIESER Datei, nicht in einer zweiten Liste daneben. */
$bw_quelle = (string) @file_get_contents(__FILE__);
if ($bw_quelle === '') {
    bw_zeile($bw_selbst, bw_t('TEXT.S_REITER'), 2, bw_t('TEXT.S_NICHT_GELESEN'));
} else {
    preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $bw_quelle, $bw_m1);
    preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $bw_quelle, $bw_m2);
    $bw_leiste = array_unique($bw_m1[1]); sort($bw_leiste);
    $bw_flaechen = array_unique($bw_m2[1]); sort($bw_flaechen);
    $bw_soll = $bw_reiter; sort($bw_soll);
    bw_zeile($bw_selbst, bw_t('TEXT.S_REITER'),
        ($bw_leiste === $bw_flaechen && $bw_leiste === $bw_soll) ? 1 : 0,
        sprintf('%d / %d / %d', count($bw_leiste), count($bw_flaechen), count($bw_soll)));

    /* Ein Formular, das das Merkmal nicht mitfuehrt, tut nichts und meldet
       stattdessen einen CSRF-Fehler - der Bediener sucht dann einen Fehler,
       den es nicht gibt. Gezaehlt wird am Quelltext: Formulare gegen die
       Aufrufe des Bausteins, der das versteckte Feld erzeugt.
       Der Name dieses Bausteins steht in diesem Kommentar bewusst NICHT
       ausgeschrieben: die Zaehlung liest diese Datei, und ein Erklaertext,
       der die gesuchte Form woertlich enthaelt, wird mitgezaehlt. Das
       Suchmuster selbst bleibt EIN Literal - es in zwei Teile zu zerlegen
       waere keine Abhilfe, sondern machte die Zeile blind, und blind ist
       kein Fortschritt gegenueber rot. */
    $bw_formulare = preg_match_all('/<form\s/', $bw_quelle);
    $bw_marken = preg_match_all('/echo bw_fmt\(\)/', $bw_quelle);
    bw_zeile($bw_selbst, bw_t('TEXT.S_MERKMAL'),
        ($bw_formulare > 0 && $bw_formulare === $bw_marken) ? 1 : 0,
        sprintf('%d / %d', $bw_marken, $bw_formulare));
}

/* Die Lage der Konfiguration. Jeder Zustand, den der Code erzeugen kann,
   braucht seinen Satz - sonst steht im Reiter Test der rohe Schluessel. */
$bw_lage = bw_config_lage();
$bw_lagetext = bw_t('TEXT.LAGE_' . strtoupper($bw_lage['lage']));
bw_zeile($bw_selbst, bw_t('TEXT.S_KONFIG'),
    in_array($bw_lage['lage'], array('ok', 'leer', 'neu'), true) ? 1
        : ($bw_lage['lage'] === 'aus_zweitschrift' ? 2 : 0),
    $bw_lagetext);

/* Vollstaendig heisst: jeder Schluessel der Vorgaben steht wirklich in der
   Datei. Zwei Zahlen, und im Fehlerfall die Namen.
   Gibt es die Datei noch gar nicht, urteilt diese Zeile ueber eine LEERE
   MENGE - und "9 von 9 vollstaendig" waere dann eine wahre Aussage, die
   nichts sagt. Genau an dieser Stelle sieht jemand hin, WEIL etwas nicht
   stimmt; ein Haken ueber nichts ist dort schlimmer als kein Haken. */
$bw_alle = count(bw_vorgaben());
if ($bw_lage['lage'] === 'neu') {
    bw_zeile($bw_selbst, bw_t('TEXT.S_VOLL'), 2, bw_t('TEXT.S_KEINE_DATEI'));
} else {
bw_zeile($bw_selbst, bw_t('TEXT.S_VOLL'),
    (!$bw_lage['fehlend'] && !$bw_lage['verworfen']) ? 1 : 0,
    $bw_lage['fehlend'] || $bw_lage['verworfen']
        ? sprintf('%d/%d - %s', $bw_alle - count($bw_lage['fehlend']), $bw_alle,
                  trim(($bw_lage['fehlend'] ? bw_t('TEXT.S_FEHLT') . ' ' . implode(', ', $bw_lage['fehlend']) . ' ' : '')
                     . ($bw_lage['verworfen'] ? bw_t('TEXT.S_VERWORFEN') . ' ' . implode(', ', $bw_lage['verworfen']) : '')))
        : sprintf('%d/%d', $bw_alle, $bw_alle));
}

/* VIER Stufen bis zur Wurzel: <ordner> -> plugins -> htmlauth ->
   webfrontend. Drei blieben bei webfrontend stehen und suchten darunter
   ein bin/ - das gibt es dort nicht. */
$bw_bin = dirname(dirname(dirname(dirname(__DIR__)))) . '/bin/plugins/'
        . bw_paths()['plugin'] . '/bw_lauf.php';
$bw_binenv = getenv('LBPBINDIR');
if ($bw_binenv !== false && $bw_binenv !== '') { $bw_bin = $bw_binenv . '/bw_lauf.php'; }
bw_zeile($bw_selbst, bw_t('TEXT.S_LAUF'), is_file($bw_bin) ? 1 : 0,
    is_file($bw_bin) ? $bw_bin : bw_t('TEXT.S_NICHT_GEFUNDEN'));

bw_zeile($bw_selbst, bw_t('TEXT.S_MS'), $bw_ms ? 1 : 0,
    $bw_ms ? count($bw_ms) . ' x' : bw_t('TEXT.KEIN_MS'));

/* Der Cron-Eintrag. Ohne ihn steht das Plugin da und tut nichts, und man
   sieht es an nichts - genau das ist am 24.08.2026 passiert: die 0.9.0 legte
   cron/cron.15min als ORDNER an statt als Datei, der Installer machte daraus
   einen Unterordner, und LoxBerry fuehrt in diesen Verzeichnissen nur Dateien
   aus. Eine Stunde lang kam kein einziger Lauf, und die Oberflaeche meldete
   nichts, weil sie gar nicht hinsah.
   Ohne bekannte Wurzel ist die Frage NICHT MESSBAR - ein Kreuz waere dort
   ein Kreuz, das nichts bedeutet. */
$bw_cron = ($bw_lb !== '') ? $bw_lb . '/system/cron/cron.05min/' . bw_paths()['plugin'] : '';
if ($bw_cron === '') {
    bw_zeile($bw_selbst, bw_t('TEXT.S_CRON'), 2, bw_t('TEXT.S_KEINE_WURZEL'));
} else {
    bw_zeile($bw_selbst, bw_t('TEXT.S_CRON'), is_file($bw_cron) ? 1 : 0,
        is_file($bw_cron) ? $bw_cron
            : (is_dir($bw_cron) ? bw_t('TEXT.S_CRON_ORDNER') : bw_t('TEXT.S_NICHT_GEFUNDEN')));
}

/* Ein Rest aus der 0.9.0. Er schadet nicht, aber er gehoert weg - und man
   findet ihn sonst nie wieder. */
$bw_rest = ($bw_lb !== '') ? $bw_lb . '/system/cron/cron.15min/' . bw_paths()['plugin'] : '';
$bw_restda = ($bw_rest !== '' && file_exists($bw_rest));
if ($bw_restda) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_REST'), 0, $bw_rest);
}

$bw_cfgd = bw_paths()['cfgdatei'];
bw_zeile($bw_selbst, bw_t('TEXT.S_SCHREIBBAR'),
    is_writable(is_file($bw_cfgd) ? $bw_cfgd : dirname($bw_cfgd)) ? 1 : 0, $bw_cfgd);

/* Die Sprachdateien: gleiche Schluesselmenge in beiden Dateien. Ein
   Schluessel, den nur eine kennt, faellt in der anderen Sprache auf den
   rohen Schluesselnamen zurueck. */
$bw_lo = bw_sprachordner() . '/lang';
$bw_de = @parse_ini_file($bw_lo . '/language_de.ini', true, INI_SCANNER_RAW);
$bw_en = @parse_ini_file($bw_lo . '/language_en.ini', true, INI_SCANNER_RAW);
if (!is_array($bw_de) || !is_array($bw_en)) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_SPRACHE'), 0, bw_t('TEXT.S_SPRACHE_UNLESBAR'));
} else {
    $bw_flach = function ($t) {
        $a = array();
        foreach ($t as $s => $w) {
            if (is_array($w)) { foreach ($w as $k => $v) { $a[] = $s . '.' . $k; } }
        }
        sort($a);
        return $a;
    };
    $bw_kd = $bw_flach($bw_de);
    $bw_ke = $bw_flach($bw_en);
    $bw_diff = array_merge(array_diff($bw_kd, $bw_ke), array_diff($bw_ke, $bw_kd));
    bw_zeile($bw_selbst, bw_t('TEXT.S_SPRACHE'), $bw_diff ? 0 : 1,
        $bw_diff ? sprintf('%d / %d - %s', count($bw_kd), count($bw_ke),
                           implode(', ', array_slice($bw_diff, 0, 6)))
                 : sprintf('%d / %d', count($bw_kd), count($bw_ke)));
}

/* ---- die Zeilen der neuen Funktionen ---------------------------------- */

/* Der Endpunkt. Gemessen wird die DATEI im unangemeldeten Baum - nicht ein
   Aufruf ueber das Netz: ein HTTP-Aufruf auf die eigene Maschine haengt an
   Namensaufloesung, Port und Proxy und wuerde hier rot werden, wo nichts rot
   ist. Wer den Weg wirklich messen will, hat dafuer den Selbsttest des
   Endpunkts (?selftest=1) - der Link steht darunter. */
$bw_ep = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . bw_paths()['plugin'] . '/index.php';
if (!is_file($bw_ep) && is_file(dirname(__DIR__) . '/html/index.php')) {
    $bw_ep = dirname(__DIR__) . '/html/index.php';
}
bw_zeile($bw_selbst, bw_t('TEXT.S_ENDPUNKT'), is_file($bw_ep) ? 1 : 0,
    is_file($bw_ep) ? $bw_ep : bw_t('TEXT.S_NICHT_GEFUNDEN'));

/* Das Merkwort. Ohne es antwortet der Endpunkt jedem mit 403 - auch dem
   Miniserver. Ein leeres Merkwort ist deshalb ein Befund und keine
   Einstellungssache. */
$bw_tok = (string) $bw_cfg['aktionstoken'];
bw_zeile($bw_selbst, bw_t('TEXT.S_TOKEN'), $bw_tok !== '' ? 1 : 0,
    $bw_tok !== '' ? strlen($bw_tok) . ' ' . bw_t('TEXT.S_ZEICHEN') : bw_t('TEXT.S_KEIN_TOKEN'));

/* MQTT. Ist es AUS, wird nichts behauptet - ein Kreuz waere dann ein Kreuz
   ueber eine Funktion, die niemand haben wollte. Ist es AN, haengt alles am
   Gateway: laeuft er nicht, gehen die Zeilen ins Leere, und das UDP-Senden
   meldet trotzdem Erfolg. */
if (empty($bw_cfg['mqtt_ein'])) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_MQTT'), 2, bw_t('TEXT.S_MQTT_AUS'));
} else {
    $bw_g2 = bw_mqtt_gateway_info();
    if ($bw_g2 === null) {
        bw_zeile($bw_selbst, bw_t('TEXT.S_MQTT'), 2, bw_t('MQTT.KEIN_GENERAL'));
    } else {
        bw_zeile($bw_selbst, bw_t('TEXT.S_MQTT'), $bw_g2['autostart'] ? 1 : 0,
            sprintf('V%s, UDP %d, %s',
                    $bw_g2['fassung'] > 0 ? (string) (int) $bw_g2['fassung'] : '?',
                    (int) $bw_g2['udpport'],
                    $bw_g2['autostart'] ? bw_t('MQTT.LAEUFT') : bw_t('MQTT.LAEUFT_NICHT')));
    }
}

/* Der Healthcheck. LoxBerry ruft ihn nur auf, wenn die Datei im bin-Ordner
   liegt UND ausfuehrbar ist. Auf einem Windows-Arbeitsplatz ist das
   Ausfuehrbit nicht messbar - dann steht dort ein Strich und keine
   Behauptung. */
$bw_hc = dirname($bw_bin) . '/healthcheck';
if (!is_file($bw_hc)) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_HEALTH'), 0, bw_t('TEXT.S_NICHT_GEFUNDEN'));
} elseif (DIRECTORY_SEPARATOR !== '/') {
    bw_zeile($bw_selbst, bw_t('TEXT.S_HEALTH'), 2, bw_t('TEXT.S_KEIN_XBIT'));
} else {
    bw_zeile($bw_selbst, bw_t('TEXT.S_HEALTH'), is_executable($bw_hc) ? 1 : 0,
        is_executable($bw_hc) ? $bw_hc : bw_t('TEXT.S_NICHT_AUSFUEHRBAR'));
}

/* Die Zweitschrift liegt NEBEN dem Konfigurationsordner, nicht darin: der
   Installer raeumt den Ordner bei jedem Upgrade aus. Fehlt sie, ist noch nie
   gespeichert worden - das ist kein Fehler, aber es ist auch kein Haken. */
$bw_zw = bw_paths()['sicherung'];
if (is_file($bw_zw)) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_ZWEIT'), 1,
        date('Y-m-d H:i', (int) @filemtime($bw_zw)));
} else {
    bw_zeile($bw_selbst, bw_t('TEXT.S_ZWEIT'), 2, bw_t('TEXT.S_ZWEIT_KEINE'));
}

$bw_striche = 0;
foreach ($bw_selbst as $bw_z2) { if ($bw_z2['ok'] === 2) { $bw_striche++; } }
?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('TEXT.PRUEFPUNKT'); ?></th><th><?php echo bw_t('TEXT.ERGEBNIS'); ?></th><th><?php echo bw_t('TEXT.GEMESSEN'); ?></th></tr>
<?php foreach ($bw_selbst as $bw_z) { ?>
  <tr><td><?= bw_e($bw_z['was']) ?></td>
      <td><?php
        if ($bw_z['ok'] === 1) { echo '<span class="sm-an">' . bw_e(bw_t('TEXT.S_OK')) . '</span>'; }
        elseif ($bw_z['ok'] === 2) { echo '<span class="sm-grau">' . bw_e(bw_t('TEXT.S_UNKLAR')) . '</span>'; }
        else { echo '<span class="sm-aus">' . bw_e(bw_t('TEXT.S_NOK')) . '</span>'; }
      ?></td>
      <td class="sm-mono"><?= bw_e($bw_z['wie']) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= sprintf(bw_e(bw_t('TEXT.S_BILANZ')), count($bw_selbst), $bw_striche) ?></p>
<?php if ($bw_restda) { ?>
<div class="sm-warnung"><?php echo bw_t('TEXT.REST_ERKL'); ?></div>
<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="rest_weg" value="1"><?php echo bw_t('TEXT.REST_WEG'); ?></button>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span></div>
</form>
<?php } ?>

<h3><?php echo bw_t('TEXT.H_ENDPUNKT'); ?></h3>
<div class="sm-hinweis"><?php echo bw_t('TEXT.H_ENDPUNKT_ERKL'); ?></div>
<?php if ($bw_tok === '') { ?>
<div class="sm-warnung"><?php echo bw_t('TEXT.S_KEIN_TOKEN'); ?></div>
<?php } else { ?>
<p class="sm-mono">http://<?= bw_e(bw_wirtsname()) ?><?= bw_e(bw_endpunkt_pfad(array('token' => $bw_tok, 'selftest' => '1'))) ?></p>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_ENDPUNKT_SELBST'); ?></p>
<?php } ?>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo bw_t('TEXT.H_PROTOKOLL'); ?></h2>
<?php
/* Der Hausstandard zeigt hier LBWeb::loglist_html() - damit sind auch
   aeltere Dateien erreichbar und die Ansicht sieht aus wie in jedem anderen
   Plugin. Die eigene Ansicht bleibt als Rueckfall, wenn es die Funktion
   nicht gibt (aeltere LoxBerry-Fassung, oder der Rahmen ist gar nicht
   geladen). */
$bw_eigen = true;
if ($bw_rahmen && method_exists('LBWeb', 'loglist_html')) {
    $bw_aus = '';
    try {
        ob_start();
        LBWeb::loglist_html();
        $bw_aus = (string) ob_get_clean();
    } catch (Throwable $bw_t2) {
        $bw_aus = (string) ob_get_clean();
    }
    if (trim($bw_aus) !== '') { echo $bw_aus; $bw_eigen = false; }
}
if ($bw_eigen) {
    $bw_logdatei = bw_paths()['logdatei'];
    $bw_zeilen = is_file($bw_logdatei)
        ? array_slice(array_reverse(file($bw_logdatei, FILE_IGNORE_NEW_LINES) ?: array()), 0, 80)
        : array();
    if ($bw_zeilen) {
        echo '<div class="sm-log">' . bw_e(implode("\n", $bw_zeilen)) . '</div>';
    } else {
        echo '<p class="sm-grau">' . bw_e(bw_t('TEXT.PROTOKOLL_LEER')) . '</p>';
    }
}
?>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_PROTOKOLL_HILFE'); ?></p>
<div class="sm-warnung"><?php echo bw_t('TEXT.H_PROTOKOLL_RAMDISK'); ?></div>
</div>

</div>
<?php
if ($bw_rahmen) {
    LBWeb::lbfooter();
}
