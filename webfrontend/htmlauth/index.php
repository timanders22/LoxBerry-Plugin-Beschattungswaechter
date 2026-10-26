<?php
/**
 * Beschattungswaechter - Bedienoberflaeche
 *
 * Diese Datei ist NUR Oberflaeche. Der Unterbau steht in
 * webfrontend/html/bw_lib.php, der Lauf in bin/bw_lauf.php.
 *
 * (c) Beschattungswaechter Plugin Authors - MIT-Lizenz
 */
/* B10 (Gruppe 23, Bauform Marstek M2): keine PHP-Meldung in die Seite oder in einen Download - am Geraet steht
   display_errors ohnehin auf Off; Meldungen gehen ins Fehlerprotokoll des Webservers. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

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
/* Installiert oder Archiv entscheidet der eigene Ablageort: liegt diese Datei
   unter .../htmlauth/plugins/<ordner>, ist sie installiert. Bis 0.9.19 kam der
   installierte Kandidat auch im Archiv VOR der eigenen Bibliothek - aus einem
   Archiv unter / war das /html/plugins/htmlauth/bw_lib.php ab der
   Laufwerkswurzel, und was dort lag, lief als Bibliothek (in WSL gemessen,
   Pruefung-Beschattungswaechter-0.9.20, Fall T3). */
if (basename(dirname(__DIR__)) === 'plugins') {
    $bw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/'
                     . basename(__DIR__) . '/bw_lib.php';
} else {
    $bw_kandidaten[] = dirname(__DIR__) . '/html/bw_lib.php';
}
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
if (in_array(bw_eingabe($_POST, 'activetab'), $bw_reiter, true)) {
    $bw_tab = bw_eingabe($_POST, 'activetab');
} elseif (in_array('tab-' . bw_eingabe($_GET, 'form'), $bw_reiter, true)) {
    $bw_tab = 'tab-' . bw_eingabe($_GET, 'form');
}
/* B1 (Gruppe 23, wie Docker D22) und N16: gemessen wird im Reiter Test nur, wenn er AUSDRUECKLICH angefordert ist
   (GET index.php?form=test) - nicht nach jedem POST aus dem Reiter (activetab) und nicht beim Umschalten im Browser.
   Das Reiter-JS laedt den Reiter Test deshalb neu, statt ihn nur einzublenden. */
$bw_test_offen = (bw_eingabe($_GET, 'form') === 'test'
                  && (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'));

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
/* X-2: die eingetippten Werte eines beanstandeten Formulars (bw_eingaben_sammeln()). */
$bw_eingaben = null;

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
 * Beanstandet wird je Feld - und BEI EINER BEANSTANDUNG WIRD NICHTS
 * GESPEICHERT, auch nicht die uebrigen Felder (Entscheidung 16 vom
 * 30.09.2026, Regeln/04). Bis 0.9.23 speicherte der Handler alles Uebrige,
 * damit niemand Zeitfenster und Abstand neu tippen musste; das leistet jetzt
 * X-2: die eingetippten Werte stehen nach der Umleitung wieder im Formular,
 * das beanstandete Feld ist rot umrandet. "Gespeichert" neben "beanstandet"
 * sagte zwei Dinge zugleich. Eine halb ausgefuellte Zielzeile ist eine
 * Beanstandung wie jede andere.
 *
 * Geprueft wird mit bw_wert_pruefen() - derselben Positivliste, gegen die
 * auch die Sicherungsdatei und die Konfigurationsdatei gehalten werden. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['speichern'])) {
    $bw_neu = $bw_cfg;
    /* Die Felder, die eine Beanstandung ausloesten (X-2). */
    $bw_beanstandet = array();
    $bw_neu['aktiv'] = empty($_POST['aktiv']) ? 0 : 1;

    /* Der Miniserver wird ueber seinen SCHLUESSEL gespeichert, nicht ueber
       seine Stellung - siehe bw_vorgaben(). Die Stellung wird mitgefuehrt,
       damit eine aeltere Fassung des Plugins die Datei weiter lesen kann. */
    $bw_wahl_roh = isset($_POST['ms_nr']) ? $_POST['ms_nr'] : '';
    $bw_wahl = bw_eingabe($_POST, 'ms_nr');
    /* NICHTS WIRD STILL UMGEDEUTET (Entscheidung 19, B-Nachzug 01.10.2026).
       Bis 0.9.24 wurde ein Feld statt Text (ms_nr[]=1) zu '', und ein
       leerer Schluessel wurde gespeichert, obwohl LoxBerry Miniserver kennt -
       danach galt still die Stellung statt des Schluessels. Das Formular
       bietet '' nur an, wenn es keinen Miniserver gibt. */
    $bw_wahl_gilt = is_string($bw_wahl_roh) && bw_wert_pruefen('ms_nr', $bw_wahl)
        && !($bw_wahl === '' && bw_miniserver());
    if ($bw_wahl_gilt) {
        $bw_neu['ms_nr'] = $bw_wahl;
        foreach (bw_miniserver() as $bw_i => $bw_m) {
            if ($bw_m['nr'] === $bw_wahl) { $bw_neu['ms'] = $bw_i; break; }
        }
    } else {
        $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'), bw_e(bw_t('TEXT.L_MS')),
            bw_e(($bw_wahl === '' && is_string($bw_wahl_roh)) ? bw_t('TEXT.KURZ_LEER') : bw_kurz($bw_wahl_roh)));
        $bw_beanstandet[] = 'ms_nr';
    }

    /* B9 (Gruppe 23): wetter_token wird unten eigens behandelt (Passwortfeld); anwesend_frist (V3) ist neu. */
    $bw_felderliste = array('uuid', 'befehl', 'von', 'bis', 'abstand',
                            'sonne_min', 'wind_max', 'fassade', 'anwesend_frist');
    for ($bw_i = 2; $bw_i <= 6; $bw_i++) {
        $bw_felderliste[] = 'uuid' . $bw_i;
        $bw_felderliste[] = 'befehl' . $bw_i;
        /* Sonne-1: die Fassaden der Ziele 2..6 (Ziel 1: 'fassade'). */
        $bw_felderliste[] = 'fassade' . $bw_i;
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
            if ($bw_grund === 'uuid' || $bw_grund === 'befehl' || $bw_grund === 'fassade') {
                $bw_nr = substr($bw_f, strlen($bw_grund));
                $bw_bez .= ' (' . bw_t('TEXT.ZIEL') . ' ' . ($bw_nr !== '' ? $bw_nr : '1') . ')';
            }
            $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'),
                                   bw_e($bw_bez), bw_e(bw_kurz($bw_w)));
            $bw_beanstandet[] = $bw_f;
        }
    }
    /* B9 (Gruppe 23, E-E16; Form Sprachmodul 1.1.4 :2133-2150): das Wortzeichen der Ecowitt-Weiche ist ein
       Passwortfeld ohne Wert. Leer heisst behalten, der Haken "Wortzeichen loeschen" leert es, Haken UND neuer Wert
       ist ein Widerspruch. In keiner Meldung steht das Wortzeichen, nur seine Laenge; nach einer Beanstandung reist es
       nie mit (X-2). Ein gespeichertes Wortzeichen bleibt beim Update stehen. */
    $bw_tok_roh = isset($_POST['wetter_token']) ? $_POST['wetter_token'] : '';
    $bw_tok_weg = !empty($_POST['wetter_token_loeschen']);
    if (!is_string($bw_tok_roh)) {
        $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'), bw_e(bw_t('TEXT.L_WETTER_TOKEN')), bw_e(bw_kurz($bw_tok_roh)));
        $bw_beanstandet[] = 'wetter_token';
    } else {
        $bw_tok_neu = trim($bw_tok_roh);
        if ($bw_tok_weg && $bw_tok_neu !== '') {
            $bw_fehler[] = bw_t('TEXT.TOKEN_LOESCHEN_WIDERSPRUCH');
            $bw_beanstandet[] = 'wetter_token';
        } elseif ($bw_tok_weg) {
            $bw_neu['wetter_token'] = '';
        } elseif ($bw_tok_neu !== '' && bw_wert_pruefen('wetter_token', $bw_tok_neu)) {
            $bw_neu['wetter_token'] = $bw_tok_neu;
        } elseif ($bw_tok_neu !== '') {
            $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'), bw_e(bw_t('TEXT.L_WETTER_TOKEN')),
                                   bw_e(sprintf(bw_t('TEXT.GEHEIM_LAENGE'), strlen($bw_tok_roh))));
            $bw_beanstandet[] = 'wetter_token';
        }
    }
    $bw_neu['pruefen_ein'] = empty($_POST['pruefen_ein']) ? 0 : 1;
    $bw_neu['wetter_ein'] = empty($_POST['wetter_ein']) ? 0 : 1;
    $bw_neu['sonne_ein'] = empty($_POST['sonne_ein']) ? 0 : 1;
    $bw_neu['anwesend_ein'] = empty($_POST['anwesend_ein']) ? 0 : 1;
    /* EINE HALB AUSGEFUELLTE ZEILE WIRD GENANNT, NICHT UEBERGANGEN.
       Seit 0.9.13 darf der Befehl eines weiteren Ziels leer sein - so raeumt
       man es aus. Steht dann aber noch eine Kennung da, wird diese Zeile
       stillschweigend nicht gesendet (bw_ziele zaehlt nur vollstaendige
       Ziele), und der Bediener saehe nichts als eine Zeile, die nichts tut. */
    /* B2 (Gruppe 23): beide Richtungen. "Kennung leer, Befehl gefuellt" ist halb - AUSSER der Befehl ist der
       Werksbefehl der Zeile (N2: die Ziele 2-6 tragen ab Werk autoshade/1 ohne Kennung; sonst beanstandete jedes
       Speichern einer Neuinstallation). Eine Fassade an einem Ziel ohne Kennung wird gespeichert und genannt. */
    $bw_werk = bw_vorgaben();
    for ($bw_i = 1; $bw_i <= 6; $bw_i++) {
        $bw_s = ($bw_i === 1) ? '' : (string) $bw_i;
        $bw_hu = isset($bw_neu['uuid' . $bw_s]) ? trim((string) $bw_neu['uuid' . $bw_s]) : '';
        $bw_hb = isset($bw_neu['befehl' . $bw_s]) ? trim((string) $bw_neu['befehl' . $bw_s]) : '';
        $bw_hf = isset($bw_neu['fassade' . $bw_s]) ? trim((string) $bw_neu['fassade' . $bw_s]) : '';
        if ($bw_hu !== '' && $bw_hb === '') {
            $bw_fehler[] = sprintf(bw_t('TEXT.ZIEL_HALB'), $bw_i);
            $bw_beanstandet[] = 'befehl' . $bw_s;
        } elseif ($bw_hu === '' && $bw_hb !== '' && $bw_hb !== $bw_werk['befehl' . $bw_s]) {
            $bw_fehler[] = sprintf(bw_t('TEXT.ZIEL_HALB_A'), $bw_i);
            $bw_beanstandet[] = 'uuid' . $bw_s;
        }
        if ($bw_hu === '' && $bw_hf !== '') {
            $bw_meldungen[] = sprintf(bw_t('TEXT.ZIEL_FASSADE_OHNE'), $bw_i);
        }
    }
    if ($bw_beanstandet) {
        /* Entscheidung 16: nichts speichern; die Eingaben reisen mit (X-2). */
        $bw_fehler[] = bw_t('TEXT.EINGABEN_ZURUECK');
        $bw_eingaben = bw_eingaben_sammeln('settings', $_POST, $bw_beanstandet);
    } elseif (bw_config_speichern($bw_neu)) {
        $bw_meldungen[] = bw_t('TEXT.GESPEICHERT');
        $bw_cfg = bw_config();
        /* M1: eingeschaltet und Ziele gehen sofort hinaus, nicht erst mit dem
           naechsten Befehl - bis 0.9.21 stand nach dem Abschalten ueber MQTT
           weiter aktiv 1 im Broker. */
        bw_mqtt_zustaende($bw_cfg, true);
        /* Sonne-1 und Anwesenheit (Gruppe 23): beide aus - der Haus-Hoerer endet binnen einer Sekunde, nicht erst mit
           seiner Hoechstdauer. */
        if (empty($bw_cfg['sonne_ein']) && empty($bw_cfg['anwesend_ein'])) {
            bw_sonne_hoerer_abmelden();
        }
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
    /* V1 (Gruppe 23): "Befehle ueber MQTT annehmen" - ab Werk aus, wirkt nur mit MQTT an. */
    $bw_neu['befehle_mqtt_ein'] = empty($_POST['befehle_mqtt_ein']) ? 0 : 1;
    $bw_th_roh = isset($_POST['mqtt_thema']) ? $_POST['mqtt_thema'] : '';
    $bw_th = bw_eingabe($_POST, 'mqtt_thema');
    /* LEER IST EINE BEANSTANDUNG, KEINE VORGABE (Entscheidung 19, B-Nachzug
       01.10.2026). Von 0.9.22 (O8) bis 0.9.24 trug der Handler fuer ein
       leeres Feld - auch eines aus Leerzeichen oder ein Feld statt Text -
       still die Vorgabe ein und speicherte, samt Haken. Jetzt wird nichts
       gespeichert, das Feld ist markiert, und die Meldung nennt die Vorgabe
       zum Eintippen. Still bleibt nur der Leerraum am Rand. */
    $bw_mq_beanstandet = array();
    if (is_string($bw_th_roh) && $bw_th === '') {
        $bw_fehler[] = sprintf(bw_t('MQTT.THEMA_LEER'), bw_e(bw_vorgaben()['mqtt_thema']));
        $bw_mq_beanstandet[] = 'mqtt_thema';
    } elseif (is_string($bw_th_roh) && bw_wert_pruefen('mqtt_thema', $bw_th)) {
        $bw_neu['mqtt_thema'] = $bw_th;
    } else {
        $bw_fehler[] = sprintf(bw_t('TEXT.FELD_ABGEWIESEN'),
                               bw_e(bw_t('TEXT.L_THEMA')), bw_e(bw_kurz($bw_th_roh)));
        $bw_mq_beanstandet[] = 'mqtt_thema';
    }
    $bw_vorher = $bw_cfg;
    if ($bw_mq_beanstandet) {
        /* Entscheidung 16: nichts speichern, auch nicht den Haken; die
           Eingaben reisen mit (X-2). */
        $bw_fehler[] = bw_t('TEXT.EINGABEN_ZURUECK');
        $bw_eingaben = bw_eingaben_sammeln('mqtt', $_POST, $bw_mq_beanstandet);
    } elseif (bw_config_speichern($bw_neu)) {
        $bw_meldungen[] = bw_t('TEXT.GESPEICHERT');
        $bw_cfg = bw_config();
        /* M2 und M4: nach einem Praefixwechsel oder dem Abschalten werden die
           zurueckbehaltenen Themen des bisherigen Praefixes abgeraeumt und
           ueber den Broker nachgelesen; die Abodatei folgt dem Praefix. M1:
           die drei Zustaende gehen sofort hinaus, unter dem neuen Praefix. */
        $bw_nach = bw_mqtt_nach_aenderung($bw_vorher, $bw_cfg);
        $bw_meldungen = array_merge($bw_meldungen, $bw_nach['ok']);
        $bw_fehler = array_merge($bw_fehler, $bw_nach['fehler']);
        bw_mqtt_zustaende($bw_cfg, true);
        /* V1 (Gruppe 23): die Befehlsbruecke sofort schalten (bw_bruecke_schalten()) - der Waechter im Takt startet
           nach einem Start hoechstens alle 600 s neu. */
        list($bw_bm_ok, $bw_bm_f) = bw_bruecke_meldungen(bw_bruecke_schalten($bw_cfg));
        $bw_meldungen = array_merge($bw_meldungen, $bw_bm_ok);
        $bw_fehler = array_merge($bw_fehler, $bw_bm_f);
    } else {
        $bw_fehler[] = bw_t('TEXT.SICH_SCHREIBFEHLER');
    }
}

/* ---------------- Der HTTP-Lesezweig (Gruppe 23, E-E13) ----------------
 *
 * Eigenes Formular 'endpunkt' im Reiter Einbindung, Abschnitt "Bestehende Anlagen". Ab Werk aus, nach einem Update an.
 * jetzt/pruefen mit Merkwort wirken unabhaengig davon. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['speichern_endpunkt'])) {
    $bw_neu = $bw_cfg;
    $bw_neu['endpunkt_ein'] = empty($_POST['endpunkt_ein']) ? 0 : 1;
    if (bw_config_speichern($bw_neu)) {
        $bw_cfg = bw_config();
        $bw_meldungen[] = bw_t('TEXT.GESPEICHERT');
        bw_log('HTTP-Lesezweig des Endpunkts ' . (!empty($bw_cfg['endpunkt_ein']) ? 'eingeschaltet' : 'ausgeschaltet') . '.');
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && bw_eingabe($_POST, 'vorlage') === 'befehle') {
    /* V1 (Gruppe 23): die Vorlage der MQTT-Befehle (bb_vorlage(), eigener Ausgang an den UDP-Eingang des Gateways) -
       ohne Merkwort, sie traegt keines. */
    $bw_vb = bw_vorlage_befehle();
    if ($bw_vb === null) {
        list(, $bw_vb_grund) = bw_bruecke_liste();
        $bw_fehler[] = bw_e(sprintf(bw_t('BEFEHL.NICHT_LADBAR'), $bw_vb_grund));
    } else {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $bw_vb[0] . '"');
        echo $bw_vb[1];
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    $bw_art = (isset($_POST['vorlage']) && is_string($_POST['vorlage'])
               && $_POST['vorlage'] === 'out') ? 'out' : 'in';
    $bw_vtoken = trim((string) (isset($bw_cfg['aktionstoken']) ? $bw_cfg['aktionstoken'] : ''));
    list($bw_dname, $bw_inhalt) = bw_vorlage($bw_art, $bw_cfg);
    /* Wohlgeformt oder nicht - das ist nicht verhandelbar. Der Anwender
       merkt eine kaputte Vorlage sonst erst in Loxone Config, und dort sucht
       er den Fehler bei sich. */
    $bw_frueher = libxml_use_internal_errors(true);
    $bw_wohl = simplexml_load_string($bw_inhalt) !== false;
    libxml_clear_errors();
    libxml_use_internal_errors($bw_frueher);
    if ($bw_vtoken === '') {
        /* OHNE MERKWORT WIRD NICHTS AUSGELIEFERT. Beide Vorlagen tragen es
           in der Adresse; ohne eines entstuende eine Datei mit leerem
           token=, und der Bediener importierte einen virtuellen Eingang, der
           auf Dauer 403 bekommt - der Grund stuende nirgends. Dieselbe
           Haltung wie eine Zeile weiter unten: lieber keine Datei als eine,
           die stumm nicht wirkt. */
        $bw_fehler[] = bw_t('TEXT.VORLAGE_OHNE_TOKEN');
    } elseif (!$bw_wohl) {
        $bw_fehler[] = bw_t('TEXT.VORLAGE_KAPUTT');
    } else {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $bw_dname . '"');
        echo $bw_inhalt;
        exit;
    }
}

/* ---------------- Den Rest der 0.9.0 wegraeumen ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rest_weg'])
        && empty($_POST['rest_bestaetigt'])) {
    /* O15, Durchgang 30.09.2026: ohne Bestaetigungshaken passiert nichts
       (Regeln/04, "Formregeln fuer einen loeschenden Knopf"). Bis 0.9.21
       loeschte ein Klick sofort. */
    $bw_fehler[] = bw_t('TEXT.REST_BESTAETIGEN');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rest_weg'])) {
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
    /* DIESELBE SPERRE WIE DER TAKT (a1), seit 0.9.31 EINE Stelle mit Endpunkt und Befehlsbruecke:
       bw_aktion_pruefen($c, 'knopf') - zaehlt sofort (ohne die Viertelstunde) und mit allen drei Zustaenden, damit der
       Grund lesbar ist; ein Fehlschlag wird vermerkt (C3), die Werte gehen sofort ueber MQTT hinaus. */
    $bw_pr = bw_aktion_pruefen($bw_cfg, 'knopf');
    if ($bw_pr['fehler'] === 'BESETZT') {
        $bw_fehler[] = bw_t('TEXT.BESETZT_ZAEHLEN');
    } elseif ($bw_pr['fehler'] === 'PRUEFEN_AUS') {
        $bw_fehler[] = bw_t('TEXT.ZAEHLEN_AUS');
    } elseif ($bw_pr['ok']) {
        $bw_zaehlung = $bw_pr['erg'];
    } else {
        $bw_fehler[] = $bw_pr['meldung'];
    }
}

/* ---------------- Senden: echt oder als Probe ---------------- */
$bw_ergebnisse = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && (isset($_POST['jetzt']) || isset($_POST['trocken']))) {
    if (isset($_POST['trocken'])) {
        /* Die Probe sendet nichts und schreibt nichts - sie braucht keine Sperre. */
        bw_trocken(true);
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
        }
    } else {
        /* DIESELBE SPERRE WIE LAUF UND ENDPUNKT (C2), seit 0.9.31 EINE Stelle mit Endpunkt und Befehlsbruecke:
           bw_aktion_jetzt($c, 'knopf') - Stand unter der Sperre frisch, Rechnung bw_stand_nach_senden(), Lebenszeichen
           ohne Takt, MQTT sofort. Der Knopf sendet wie bisher auch bei ausgeschaltetem Waechter (zum Ausprobieren). */
        $bw_jr = bw_aktion_jetzt($bw_cfg, 'knopf');
        if ($bw_jr['fehler'] === 'BESETZT') {
            $bw_fehler[] = bw_t('TEXT.BESETZT');
        } elseif ($bw_jr['fehler'] === 'KEIN_ZIEL') {
            $bw_fehler[] = bw_t('TEXT.KEIN_ZIEL');
        } else {
            $bw_ergebnisse = $bw_jr['ergebnisse'];
            if ($bw_jr['fehler'] === 'FRIST' || strpos($bw_jr['grund'], 'Frist') === 0) {
                $bw_fehler[] = bw_e($bw_jr['grund']);
            }
        }
    }
}

/* ---------------- Jalousie-Bausteine vorschlagen (Gruppe 23, V4, E-E6) ----------------
 *
 * Gruen: liest nur die Strukturdatei des Miniservers und speichert nichts. Die Auswahl fuellt danach nur das Formular
 * im Reiter Einstellungen; gespeichert wird mit dem vorhandenen orangen Speichern (B2 prueft dann). */
$bw_vorschlag = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorschlagen'])) {
    list($bw_vok, $bw_vmeld, $bw_vliste, $bw_vohne) = bw_jalousien_vorschlag($bw_cfg);
    if (!$bw_vok) {
        $bw_fehler[] = $bw_vmeld;
    } else {
        $bw_vorschlag = array('liste' => $bw_vliste, 'ohne' => $bw_vohne);
        if (!$bw_vliste) {
            $bw_fehler[] = bw_t('TEXT.VORSCHLAG_LEER');
        }
    }
}

/* ---------------- Protokoll leeren (Gruppe 23, B6b) ----------------
 *
 * Orange: setzt beschattung.log und cron.err auf 0 Byte, loescht nie eine Datei. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['protokoll_leeren'])) {
    $bw_pl = bw_protokoll_leeren();
    $bw_pl_gut = array_keys(array_filter($bw_pl));
    $bw_pl_fehl = array_keys(array_diff_key($bw_pl, array_flip($bw_pl_gut)));
    if (!$bw_pl) {
        $bw_meldungen[] = bw_t('TEXT.LEEREN_NICHTS');
    }
    if ($bw_pl_gut) {
        $bw_meldungen[] = sprintf(bw_t('TEXT.LEEREN_OK'), bw_e(implode(', ', $bw_pl_gut)));
    }
    if ($bw_pl_fehl) {
        $bw_fehler[] = sprintf(bw_t('TEXT.LEEREN_FEHL'), bw_e(implode(', ', $bw_pl_fehl)));
    }
}

$bw_stand = bw_stand_lesen();
$bw_ms = bw_miniserver();

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration samt lesbarem Kopf, und das
 * Aktionstoken ist DARUNTER: ohne dasselbe Merkwort zeigen nach dem
 * Zurueckspielen alle Adressen in der Loxone-Projektdatei ins Leere.
 *
 * Damit ist die Datei vertraulich, und der Hinweis am Knopf sagt genau das.
 * Bis 0.9.12 sagte er das Gegenteil - ein Satz aus der 0.9.10, die noch
 * keinen Endpunkt hatte. Das Kennwort des Miniservers steht weiterhin nicht
 * darin; es liegt in der zentralen LoxBerry-Konfiguration. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bw_sichern'])) {
    $bw_js = json_encode(bw_sicherung_bauen(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($bw_js) && $bw_js !== '') {
        while (ob_get_level() > 0) { ob_end_clean(); }
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
        $bw_vorher = $bw_cfg;
        list($bw_neu, $bw_mangel, $bw_n, $bw_shinweise) = bw_sicherung_lesen(
            (string) @file_get_contents($_FILES['bw_sicherung']['tmp_name']));
        if ($bw_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $bw_fehler[] = bw_t('TEXT.SICH_ABGELEHNT') . ' '
                            . implode(' ', $bw_mangel);
        } elseif (bw_config_speichern($bw_neu)) {
            $bw_meldungen[] = sprintf(bw_t('TEXT.SICH_UEBERNOMMEN'), $bw_n);
            /* Was die Datei NICHT enthielt, gehoert in die Meldung. Eine
               Zahl allein ("21 Werte uebernommen") verschweigt genau den
               Fall, in dem der Bediener etwas wissen muss. */
            foreach ($bw_shinweise as $bw_h) { $bw_meldungen[] = $bw_h; }
            /* Die Felder darunter zeigen sonst weiter den alten Stand: der
               Bediener sieht keine Aenderung und drueckt noch einmal. */
            $bw_cfg = bw_config();
            $bw_meldungen[] = bw_t('TEXT.SICH_DANACH');
            /* Wie nach dem Speichern im Reiter MQTT (M1, M2, M4): eine
               Sicherung kann Schalter und Praefix aendern. */
            $bw_nach = bw_mqtt_nach_aenderung($bw_vorher, $bw_cfg);
            $bw_meldungen = array_merge($bw_meldungen, $bw_nach['ok']);
            $bw_fehler = array_merge($bw_fehler, $bw_nach['fehler']);
            bw_mqtt_zustaende($bw_cfg, true);
            /* V1 (Gruppe 23): eine Sicherung kann den Haken der Befehlsbruecke aendern. */
            list($bw_bm_ok, $bw_bm_f) = bw_bruecke_meldungen(bw_bruecke_schalten($bw_cfg));
            $bw_meldungen = array_merge($bw_meldungen, $bw_bm_ok);
            $bw_fehler = array_merge($bw_fehler, $bw_bm_f);
        } else {
            $bw_fehler[] = bw_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}

/* ---------------- Post/Redirect/Get (O1, Durchgang 30.09.2026) ----------------
 *
 * Jeder POST endet hier mit 303 auf den Reiter, in dem er abgeschickt wurde;
 * Meldungen, Beanstandungen und die Ergebnistabellen gehen als Einmalmeldung
 * mit (bw_einmal_schreiben(), 0600, hoechstens 120 s, nur beim GET gelesen).
 * Bis 0.9.21 zeichnete der POST die Seite selbst, und ein Neuladen schickte
 * ihn erneut: "Neues Merkwort erzeugen" zweimal hiess zwei neue Merkworte,
 * die eben in Loxone eingetragenen Adressen waren wieder ungueltig; "Befehl
 * jetzt senden" zweimal hiess zwei Befehle an den Miniserver (gemessen,
 * Pruefbericht Oberflaeche O1). Auch ein vom Wachposten abgewiesener POST
 * leitet um und meldet im GET. Die Herunterladeknoepfe enden vorher mit exit
 * und kommen hier nicht an. Laesst sich die Einmalmeldung nicht ablegen, wird
 * wie bisher direkt gezeichnet - lieber ein doppelter POST als eine
 * verschluckte Meldung. */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (bw_einmal_schreiben(array('meldungen' => array_values($bw_meldungen),
                                  'fehler' => array_values($bw_fehler),
                                  'ergebnisse' => array_values($bw_ergebnisse),
                                  'zaehlung' => $bw_zaehlung,
                                  'tab' => $bw_tab,
                                  'eingaben' => $bw_eingaben,
                                  'vorschlag' => $bw_vorschlag))) {
        header('Location: index.php?form=' . substr($bw_tab, 4), true, 303);
        exit;
    }
} else {
    $bw_einmal = bw_einmal_lesen();
    if (is_array($bw_einmal)) {
        $bw_meldungen = array_merge($bw_meldungen, $bw_einmal['meldungen']);
        $bw_fehler = array_merge($bw_fehler, $bw_einmal['fehler']);
        $bw_ergebnisse = $bw_einmal['ergebnisse'];
        if ($bw_einmal['zaehlung'] !== null) {
            $bw_zaehlung = $bw_einmal['zaehlung'];
        }
        if (in_array($bw_einmal['tab'], $bw_reiter, true)) {
            $bw_tab = $bw_einmal['tab'];
        }
        $bw_eingaben = $bw_einmal['eingaben'];
        $bw_vorschlag = $bw_einmal['vorschlag'];
    }
}

/* X-2: was die Formulare zeigen - nach einer Beanstandung die eingetippten
   Werte, sonst die gespeicherten (bw_formwert(), bw_formhaken(), bw_markiert()). */
$bw_eg = $bw_eingaben;

if ($bw_rahmen) {
    /* B8 (Gruppe 23): die eigene Wikiseite (HTTP 200, angelegt 01.10.2026), wie Docker/Abfahrt/Ferien. */
    LBWeb::lbheader(bw_t('ALLGEMEIN.TITEL'), 'https://wiki.loxberry.de/plugins/beschattungswaechter/start', 'help.html');
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
/* Die Regel gilt fuer JEDES Auswahlfeld, nicht nur fuer eines mit einer
   eigenen Klasse. Bis 0.9.12 stand hier 'select.sm-auswahl': heute trug das
   einzige Auswahlfeld der Seite die Klasse, und das naechste ohne sie haette
   den Pfeil stumm verloren - genau der Mangel, gegen den dieser Block
   geschrieben wurde. Wortlaut jetzt aus VORLAGE_hausstandard.css.html. */
.sm-wrap select {
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%23546e7a' stroke-width='2'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center;
  padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
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
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
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
/* Eigene Zutat (b1, Verbesserungsbau 30.09.2026): die Wirkungszeile in der
   Kachel "zuletzt gesendet" - nicht aus der Vorlage des Hausstandards. */
.sm-kachel .sm-wirkung { display: block; max-width: 340px; margin-top: 4px; color: #33691e; }
/* Eigene Zutat (X-2): ein beanstandetes Feld nach der Umleitung. */
.sm-wrap .sm-beanstandet { outline: 2px solid #c62828; outline-offset: 1px; background: #ffebee; }
/* Ergaenzung (Welle Bild, Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
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
/* O2, Durchgang 30.09.2026: der eingestellte Miniserver fehlt in der
   LoxBerry-Konfiguration. Das steht oben, nicht nur im Protokoll. */
$bw_ms_nr = trim((string) $bw_cfg['ms_nr']);
$bw_gewaehlt = bw_miniserver_gewaehlt($bw_cfg, $bw_ms);
$bw_ms_fehlt = ($bw_ms_nr !== '' && $bw_gewaehlt === null);
if ($bw_ms_fehlt) {
    echo '<div class="sm-warnung">' . sprintf(bw_t('TEXT.MS_FEHLT'), bw_e($bw_ms_nr)) . '</div>';
}
?>

<?php /* Kopf (Entscheidung Nr. 43, seit 0.9.27): Statusuebersicht ueber den
   Reitern, immer sichtbar. Nur Werte, die die Seite ohnehin liest
   (Konfiguration, stand.json, Miniserver aus general.json) - keine Anfrage
   an den Miniserver. Einen Dienst gibt es nicht: cron.05min ruft bw_lauf.php. */ ?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?= bw_e(bw_t('TEXT.KOPF_EIGENSCHAFT')) ?></th><th><?= bw_e(bw_t('TEXT.KOPF_WERT')) ?></th></tr>
<tr><td><?= bw_e(bw_t('TEXT.KOPF_WAECHTER')) ?></td>
    <td class="<?= !empty($bw_cfg['aktiv']) ? 'sm-an' : 'sm-aus' ?>"><?= bw_e(!empty($bw_cfg['aktiv'])
        ? bw_t('TEXT.KOPF_WAECHTER_EIN') : bw_t('TEXT.KOPF_WAECHTER_AUS')) ?></td></tr>
<tr><td><?= bw_e(bw_t('TEXT.L_MS')) ?></td>
    <?= $bw_ms_fehlt
        ? '<td class="sm-aus">' . bw_e(sprintf(bw_t('TEXT.MS_FEHLT_OPTION'), $bw_ms_nr)) . '</td>'
        : ($bw_gewaehlt !== null
            ? '<td>' . bw_e($bw_gewaehlt['name'] . ' (' . $bw_gewaehlt['adresse'] . ')') . '</td>'
            : '<td class="sm-aus">' . bw_e(bw_t('TEXT.KEIN_MS')) . '</td>') ?></tr>
<tr><td><?= bw_e(bw_t('TEXT.K_LETZTE')) ?></td>
    <td><?= empty($bw_stand['letzte']) ? '&mdash;' : bw_e(bw_zeitpunkt($bw_stand['letzte'])) ?></td></tr>
<tr><td><?= bw_e(bw_t('TEXT.K_LETZTE_OK')) ?></td>
    <td><?= empty($bw_stand['letzte_ok']) ? '&mdash;' : bw_e(bw_zeitpunkt($bw_stand['letzte_ok'])) ?></td></tr>
<tr><td><?= bw_e(bw_t('TEXT.K_FEHLER')) ?></td>
    <td class="<?= !empty($bw_stand['fehler']) ? 'sm-aus' : 'sm-an' ?>"><?= (int) (isset($bw_stand['fehler']) ? $bw_stand['fehler'] : 0) ?></td></tr>
</table>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN da, nicht in einer Schleife
     erzeugt. Jeder Reiter ist eine Adresse und bleibt es: wer ohne Skript
     kommt, schaltet ueber den Server um (index.php?form=...). Das Skript am
     Ende der Seite legt sich nur darueber und spart den Seitenwechsel - bis
     0.9.12 fehlte es, und jeder Reiterklick verwarf, was in einem anderen
     Reiter noch nicht gespeichert war. Ob Leiste, Bereiche und Positivliste
     dieselben Namen fuehren, zaehlt der Reiter Test nach. -->
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
<div class="sm-hinweis"><?= bw_t('TEXT.WAS_IST_DAS') ?></div>
<?php /* Gruppe 23 (N6, E-E21): EINE Legende oben je Reiter - unter dem Kasten, der als Erstes im ersten Reiter steht (kas). */ ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span> <span><i class="sm-punkt sm-b-lesen"></i> <?php echo bw_t('LEGENDE.LESEN'); ?></span></div>

<h2><?php echo bw_t('TEXT.H_EINSTELLUNGEN'); ?></h2>

<div class="sm-step"><?php echo bw_t('TEXT.WARUM'); ?></div>

<?php /* V4 (Gruppe 23): eigenes Formular, der Knopf steht ueber der Zieltabelle (form-Attribut) - ein Formular im
   Formular gibt es nicht. */ ?>
<form action="index.php" method="post" id="bw-vorschlag">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
</form>

<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<div class="sm-feld">
  <label<?= bw_markiert($bw_eg, 'aktiv', false) ?>><input data-role="none" type="checkbox" name="aktiv" value="1"<?= bw_ungueltig($bw_eg, 'aktiv') ?><?= bw_formhaken($bw_eg, 'aktiv', $bw_cfg['aktiv']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_AKTIV'); ?></label>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_AKTIV'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_MS'); ?></label>
  <select data-role="none" name="ms_nr"<?= bw_markiert($bw_eg, 'ms_nr') ?>>
<?php
/* X-2: nach einer Beanstandung die eingetippte Wahl, sonst wie bisher der
   gewaehlte (oder der fehlende, O2) Miniserver. */
$bw_ms_wahl = bw_formwert($bw_eg, 'ms_nr',
    $bw_ms_fehlt ? $bw_ms_nr : ($bw_gewaehlt !== null ? $bw_gewaehlt['nr'] : ''));
foreach ($bw_ms as $bw_m2) { ?>
    <option value="<?= bw_e($bw_m2['nr']) ?>"<?= ($bw_ms_wahl === $bw_m2['nr']) ? ' selected' : '' ?>><?= bw_e($bw_m2['name'] . ' (' . $bw_m2['adresse'] . ')') ?></option>
<?php } ?>
<?php
/* O2: fehlt der eingestellte Miniserver, steht er als markierte Option da -
   gewaehlt und mit dem BISHERIGEN Schluessel. Ein Speichern aendert ms_nr damit
   nie still. Bis 0.9.21 schickte der Browser dann die erste Option, und nach
   dem naechsten beliebigen Speichern ging "Befehl jetzt senden" an einen
   anderen Miniserver (gemessen, Pruefbericht Oberflaeche O2). */
if ($bw_ms_fehlt) { ?>
    <option value="<?= bw_e($bw_ms_nr) ?>"<?= ($bw_ms_wahl === $bw_ms_nr) ? ' selected' : '' ?>><?= bw_e(sprintf(bw_t('TEXT.MS_FEHLT_OPTION'), $bw_ms_nr)) ?></option>
<?php } ?>
<?php if (!$bw_ms && !$bw_ms_fehlt) { ?><option value=""><?php echo bw_t('TEXT.KEIN_MS'); ?></option><?php } ?>
  </select>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_MS'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_UUID'); ?></label>
  <input data-role="none" type="text" name="uuid" value="<?= bw_e(bw_formwert($bw_eg, 'uuid', $bw_cfg['uuid'])) ?>"<?= bw_markiert($bw_eg, 'uuid') ?>>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_UUID'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_BEFEHL'); ?></label>
  <input data-role="none" type="text" name="befehl" value="<?= bw_e(bw_formwert($bw_eg, 'befehl', $bw_cfg['befehl'])) ?>"<?= bw_markiert($bw_eg, 'befehl') ?>>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_BEFEHL'); ?></p>
</div>

<h3><?php echo bw_t('TEXT.H_ZIELE'); ?></h3>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_ZIELE_ERKL'); ?></p>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" form="bw-vorschlag" name="vorschlagen" value="1"><?php echo bw_t('TEXT.K_VORSCHLAG'); ?></button>
</div>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_VORSCHLAG'); ?></p>
<?php if (is_array($bw_vorschlag) && $bw_vorschlag['liste']) { ?>
<div class="sm-hinweis"><?php echo bw_t('TEXT.VORSCHLAG_HINWEIS'); ?></div>
<div class="sm-breit">
<table class="sm-tbl" id="bw-vorschlag-liste">
  <tr><th></th><th><?php echo bw_t('TEXT.V_ART'); ?></th><th><?php echo bw_t('TEXT.BAUSTEIN'); ?></th><th><?php echo bw_t('TEXT.RAUM'); ?></th><th><?php echo bw_t('TEXT.L_UUID'); ?></th><th><?php echo bw_t('TEXT.L_BEFEHL'); ?></th></tr>
<?php foreach ($bw_vorschlag['liste'] as $bw_v) { ?>
  <tr><td><input data-role="none" type="checkbox" class="bw-wahl" value="<?= bw_e($bw_v['uuid']) ?>"></td>
      <td><?= bw_e($bw_v['art'] === 'zentral' ? sprintf(bw_t('TEXT.V_ZENTRAL'), (int) $bw_v['umfasst']) : bw_t('TEXT.V_EINZELN')) ?></td>
      <td><?= bw_e($bw_v['name']) ?></td><td><?= bw_e($bw_v['raum']) ?></td>
      <td class="sm-mono"><?= bw_e($bw_v['uuid']) ?></td><td class="sm-mono">auto</td></tr>
<?php } ?>
</table>
</div>
<?php if ((int) $bw_vorschlag['ohne'] > 0) { ?>
<p class="sm-hilfe"><?= bw_e(sprintf(bw_t('TEXT.VORSCHLAG_OHNE'), (int) $bw_vorschlag['ohne'])) ?></p>
<?php } ?>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="button" id="bw-uebernehmen"><?php echo bw_t('TEXT.K_UEBERNEHMEN'); ?></button>
</div>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_UEBERNEHMEN'); ?></p>
<?php } ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?php echo bw_t('TEXT.L_UUID'); ?></th><th><?php echo bw_t('TEXT.L_BEFEHL'); ?></th></tr>
<?php for ($bw_i = 2; $bw_i <= 6; $bw_i++) {
    $bw_ku = 'uuid' . $bw_i; $bw_kb = 'befehl' . $bw_i; ?>
  <tr><td><?= (int) $bw_i ?></td>
      <td><input data-role="none" type="text" name="<?= $bw_ku ?>" value="<?= bw_e(bw_formwert($bw_eg, $bw_ku, isset($bw_cfg[$bw_ku]) ? $bw_cfg[$bw_ku] : '')) ?>"<?= bw_markiert($bw_eg, $bw_ku) ?>></td>
      <td><input data-role="none" type="text" name="<?= $bw_kb ?>" value="<?= bw_e(bw_formwert($bw_eg, $bw_kb, isset($bw_cfg[$bw_kb]) ? $bw_cfg[$bw_kb] : '')) ?>"<?= bw_markiert($bw_eg, $bw_kb) ?>></td></tr>
<?php } ?>
</table>
</div>

<div class="sm-feld">
  <label<?= bw_markiert($bw_eg, 'pruefen_ein', false) ?>><input data-role="none" type="checkbox" name="pruefen_ein" value="1"<?= bw_ungueltig($bw_eg, 'pruefen_ein') ?><?= bw_formhaken($bw_eg, 'pruefen_ein', $bw_cfg['pruefen_ein']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_PRUEFEN'); ?></label>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_PRUEFEN'); ?></p>
</div>

<div class="sm-row">
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_VON'); ?></label>
    <input data-role="none" type="text" name="von" value="<?= bw_e(bw_formwert($bw_eg, 'von', $bw_cfg['von'])) ?>" placeholder="06:00"<?= bw_markiert($bw_eg, 'von') ?>>
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_BIS'); ?></label>
    <input data-role="none" type="text" name="bis" value="<?= bw_e(bw_formwert($bw_eg, 'bis', $bw_cfg['bis'])) ?>" placeholder="21:00"<?= bw_markiert($bw_eg, 'bis') ?>>
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_ABSTAND'); ?></label>
    <input data-role="none" type="text" name="abstand" value="<?= bw_e(bw_formwert($bw_eg, 'abstand', (int) $bw_cfg['abstand'])) ?>"<?= bw_markiert($bw_eg, 'abstand') ?>>
  </div>
</div>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_FENSTER'); ?></p>

<!-- Wetter-1 (Verbesserungsbau 30.09.2026): die Ecowitt-Weiche als Quelle
     fuer Sonne und Wind, ab Werk aus. -->
<h3><?php echo bw_t('TEXT.H_WETTER'); ?></h3>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_WETTER_ERKL'); ?></p>
<div class="sm-feld">
  <label<?= bw_markiert($bw_eg, 'wetter_ein', false) ?>><input data-role="none" type="checkbox" name="wetter_ein" value="1"<?= bw_ungueltig($bw_eg, 'wetter_ein') ?><?= bw_formhaken($bw_eg, 'wetter_ein', $bw_cfg['wetter_ein']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_WETTER_EIN'); ?></label>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_WETTER_TOKEN'); ?></label>
    <!-- B9 (Gruppe 23, Form Sprachmodul 1.1.4): Passwortfeld ohne Wert; der Platzhalter nennt nur die Laenge. Das
         Wortzeichen reist nach einer Beanstandung NICHT mit (X-2), das Feld wird nur markiert. -->
    <?php $bw_wtl = strlen((string) $bw_cfg['wetter_token']); ?>
    <input data-role="none" type="password" name="wetter_token" value="" autocomplete="new-password" placeholder="<?= bw_e($bw_wtl > 0 ? sprintf(bw_t('TEXT.P_TOKEN_DA'), $bw_wtl) : bw_t('TEXT.P_TOKEN_KEINS')) ?>"<?= bw_markiert($bw_eg, 'wetter_token') ?>>
    <label><input data-role="none" type="checkbox" name="wetter_token_loeschen" value="1"> <?php echo bw_t('TEXT.L_TOKEN_LOESCHEN'); ?></label>
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_SONNE_MIN'); ?></label>
    <input data-role="none" type="text" name="sonne_min" value="<?= bw_e(bw_formwert($bw_eg, 'sonne_min', (int) $bw_cfg['sonne_min'])) ?>"<?= bw_markiert($bw_eg, 'sonne_min') ?>>
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_WIND_MAX'); ?></label>
    <input data-role="none" type="text" name="wind_max" value="<?= bw_e(bw_formwert($bw_eg, 'wind_max', (int) $bw_cfg['wind_max'])) ?>"<?= bw_markiert($bw_eg, 'wind_max') ?>>
  </div>
</div>
<p class="sm-hilfe"><?= sprintf(bw_t('TEXT.H_WETTER_FELDER'), (int) round(bw_wetter_frist() / 60)) ?></p>

<!-- Sonne-1 (Verbesserungsbau 01.10.2026): der Sonnenstand der Fensterbilanz
     ueber MQTT (haus/sonne/), ab Werk aus. Je Ziel die Fassaden, deren
     Sonne es braucht; leer = wie bisher. -->
<h3><?php echo bw_t('TEXT.H_SONNE'); ?></h3>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_SONNE_ERKL'); ?></p>
<div class="sm-feld">
  <label<?= bw_markiert($bw_eg, 'sonne_ein', false) ?>><input data-role="none" type="checkbox" name="sonne_ein" value="1"<?= bw_ungueltig($bw_eg, 'sonne_ein') ?><?= bw_formhaken($bw_eg, 'sonne_ein', $bw_cfg['sonne_ein']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_SONNE_EIN'); ?></label>
</div>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?php echo bw_t('TEXT.L_UUID'); ?></th><th><?php echo bw_t('TEXT.L_FASSADE'); ?></th></tr>
<?php for ($bw_i = 1; $bw_i <= 6; $bw_i++) {
    $bw_kf = ($bw_i === 1) ? 'fassade' : 'fassade' . $bw_i;
    $bw_ku = ($bw_i === 1) ? 'uuid' : 'uuid' . $bw_i;
    $bw_zu = isset($bw_cfg[$bw_ku]) ? trim((string) $bw_cfg[$bw_ku]) : ''; ?>
  <tr><td><?= (int) $bw_i ?></td>
      <td class="sm-mono"><?= $bw_zu !== '' ? bw_e($bw_zu) : '&ndash;' ?></td>
      <td><input data-role="none" type="text" name="<?= $bw_kf ?>" value="<?= bw_e(bw_formwert($bw_eg, $bw_kf, isset($bw_cfg[$bw_kf]) ? $bw_cfg[$bw_kf] : '')) ?>" placeholder="<?= bw_e(bw_t('TEXT.P_FASSADE')) ?>"<?= bw_markiert($bw_eg, $bw_kf) ?>></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= sprintf(bw_t('TEXT.H_SONNE_FELDER'), (int) round(bw_sonne_frist() / 60)) ?></p>

<!-- V3 (Gruppe 23): Anwesenheit vom BLE-Scanner NG (haus/anwesenheit/jemand), ab Werk aus. -->
<h3><?php echo bw_t('TEXT.H_ANWESEND'); ?></h3>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_ANWESEND_ERKL'); ?></p>
<div class="sm-feld">
  <label<?= bw_markiert($bw_eg, 'anwesend_ein', false) ?>><input data-role="none" type="checkbox" name="anwesend_ein" value="1"<?= bw_ungueltig($bw_eg, 'anwesend_ein') ?><?= bw_formhaken($bw_eg, 'anwesend_ein', $bw_cfg['anwesend_ein']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_ANWESEND_EIN'); ?></label>
</div>
<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_ANWESEND_FRIST'); ?></label>
  <input data-role="none" type="text" name="anwesend_frist" value="<?= bw_e(bw_formwert($bw_eg, 'anwesend_frist', (int) $bw_cfg['anwesend_frist'])) ?>"<?= bw_markiert($bw_eg, 'anwesend_frist') ?>>
</div>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_ANWESEND_FELDER'); ?></p>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?php echo bw_t('TEXT.SPEICHERN'); ?></button>
</div>
</form>

<h2><?= bw_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= bw_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-hinweis"><?= bw_t('TEXT.SICH_INHALT') ?></div>
<?php
/* X-3 (Verbesserungsbau 30.09.2026): ein gespeicherter Wert, den das eigene
   Zurueckspielen abweisen wuerde - gelb, nur die Namen. Die Sicherung wird
   trotzdem geliefert und traegt den Kopf _warnung. */
$bw_altwerte = bw_rueckspiel_altwerte();
if ($bw_altwerte) { ?>
<div class="sm-warnung"><?= sprintf(bw_t('TEXT.SICH_WARNUNG'), bw_e(implode(', ', $bw_altwerte))) ?></div>
<?php } ?>
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span></div>
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
    <label<?= bw_markiert($bw_eg, 'mqtt_ein', false) ?>><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= bw_ungueltig($bw_eg, 'mqtt_ein') ?><?= bw_formhaken($bw_eg, 'mqtt_ein', $bw_cfg['mqtt_ein']) ? ' checked' : '' ?>>
      <?php echo bw_t('MQTT.L_EIN'); ?></label>
    <p class="sm-hilfe"><?php echo bw_t('MQTT.H_EIN'); ?></p>
<?php if (empty($bw_cfg['mqtt_ein'])) { ?>
    <p class="sm-grau"><?php echo bw_t('MQTT.AUS_GRAU'); ?></p>
<?php } ?>
  </div>
  <div class="sm-feld">
    <label><?php echo bw_t('TEXT.L_THEMA'); ?></label>
    <input data-role="none" type="text" name="mqtt_thema" value="<?= bw_e(bw_formwert($bw_eg, 'mqtt_thema', $bw_cfg['mqtt_thema'])) ?>"<?= bw_markiert($bw_eg, 'mqtt_thema') ?>>
    <p class="sm-hilfe"><?php echo bw_t('MQTT.H_THEMA'); ?></p>
  </div>
  <h3><?php echo bw_t('BEFEHL.H_MQTT'); ?></h3>
  <div class="sm-feld">
    <label<?= bw_markiert($bw_eg, 'befehle_mqtt_ein', false) ?>><input data-role="none" type="checkbox" name="befehle_mqtt_ein" value="1"<?= bw_ungueltig($bw_eg, 'befehle_mqtt_ein') ?><?= bw_formhaken($bw_eg, 'befehle_mqtt_ein', $bw_cfg['befehle_mqtt_ein']) ? ' checked' : '' ?>>
      <?php echo bw_t('BEFEHL.L_ANNEHMEN'); ?></label>
    <p class="sm-hilfe"><?php echo bw_t('BEFEHL.H_ANNEHMEN'); ?></p>
    <p class="sm-hilfe"><?php echo bw_t('BEFEHL.H_ZWEITER_HAKEN'); ?></p>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_mqtt" value="1"><?php echo bw_t('TEXT.SPEICHERN'); ?></button>
  </div>
</form>

<?php
/* M4: das Plugin fuehrt die Abodatei des Gateways selbst. Gemessen wird, ob
   das Praefix darin steht - nicht behauptet. */
list($bw_abo_pfad, $bw_abo_da) = bw_abo_datei($bw_cfg['mqtt_thema']);
?>
<h3><?php echo bw_t('MQTT.H_ABO'); ?></h3>
<p><span class="sm-mono"><?= bw_e(bw_mqtt_praefix($bw_cfg['mqtt_thema'])) ?>/#</span></p>
<?php
/* Der Pflichtsatz haengt an der FASSUNG. Unter V2 schaltet der Kern die
   Eintragknoepfe ab - ein pauschaler V1-Satz schickte jeden V2-Anwender zu
   einem Eingabefeld, das es dort nicht mehr gibt. */
if ($bw_gwf >= 2) { ?>
<div class="sm-hinweis"><?php echo bw_t('MQTT.ABO_V2'); ?></div>
<?php } elseif ($bw_abo_da) { ?>
<div class="sm-hinweis"><?= sprintf(bw_t('MQTT.ABO_DATEI'), bw_e($bw_abo_pfad)) ?></div>
<?php if ($bw_gwf !== 1) { ?><p class="sm-hilfe"><?php echo bw_t('MQTT.ABO_V2'); ?></p><?php } ?>
<?php } elseif ($bw_gwf === 1) { ?>
<div class="sm-warnung"><?php echo bw_t('MQTT.ABO_PFLICHT'); ?></div>
<?php } else { ?>
<div class="sm-warnung"><?php echo bw_t('MQTT.ABO_PFLICHT'); ?></div>
<p class="sm-hilfe"><?php echo bw_t('MQTT.ABO_V2'); ?></p>
<?php } ?>

<?php
/* Gruppe 23 (2.5, E-E11): die V2-Hakenliste - nur bei Gatewayversion 2 oder unbekannt, aus derselben Namenstabelle wie
   der Reiter Einbindung. "Noncached" fuer status/ok und ziele: an ihnen haengen Anzeige und Benachrichtigung, und mit
   dem Dedup des Gateways blieben sie nach einem Neustart des Miniservers sonst auf dem Startwert. */
if ($bw_gwf >= 2 || $bw_gwf === 0) { ?>
<h3><?php echo bw_t('MQTT.H_HAKEN'); ?></h3>
<p class="sm-hilfe"><?php echo bw_t('MQTT.HAKEN_ERKL'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('MQTT.THEMA'); ?></th><th><?php echo bw_t('LOX.NT_NAME'); ?></th><th><?php echo bw_t('MQTT.T_HAKEN'); ?></th><th>Noncached</th></tr>
<?php foreach (bw_mqtt_namenstabelle($bw_cfg) as $bw_n) { ?>
  <tr><td class="sm-mono"><?= bw_e($bw_n['voll']) ?></td><td class="sm-mono"><?= bw_e($bw_n['name']) ?></td>
      <td><?= bw_e(bw_t($bw_n['haken'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td>
      <td><?= bw_e(bw_t($bw_n['noncached'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?php echo bw_t('MQTT.HAKEN_BRUECKE'); ?></p>
<?php } ?>

<h3><?php echo bw_t('MQTT.H_THEMEN'); ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('MQTT.THEMA'); ?></th><th><?php echo bw_t('MQTT.BEDEUTUNG'); ?></th><th><?php echo bw_t('MQTT.T_RETAIN'); ?></th></tr>
<?php
/* Die Spalte fragt bw_mqtt_retain() - DIESELBE Funktion, die auch sendet.
   Eine zweite Liste in der Oberflaeche waere eine zweite Wahrheit, und sie
   waere die, die niemand nachmisst.

   bw_mqtt_themen() liefert die Themen MIT Praefix, die Tabelle kennt sie
   ohne. Abgeschnitten wird genau der Teil, den bw_mqtt_praefix() davor
   gesetzt hat - nicht am ersten Schraegstrich, denn ein Praefix darf selbst
   einen tragen ("haus/beschattung"). */
$bw_praefix2 = bw_mqtt_praefix($bw_cfg['mqtt_thema']);
foreach (bw_mqtt_themen($bw_cfg) as $bw_th2 => $bw_bez) {
    $bw_thk = (strpos($bw_th2, $bw_praefix2 . '/') === 0)
            ? substr($bw_th2, strlen($bw_praefix2) + 1) : $bw_th2; ?>
  <tr><td class="sm-mono"><?= bw_e($bw_th2) ?></td><td><?= bw_e(bw_t($bw_bez)) ?></td><td><?= bw_e(bw_t(bw_mqtt_retain($bw_thk) ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?php echo bw_t('MQTT.RETAIN_ERKLAERUNG'); ?></div>
<div class="sm-hinweis"><?php echo bw_t('MQTT.H_LEBEN'); ?></div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo bw_t('LOX.H'); ?></h2>
<?php /* Gruppe 23 (E-E21, N6): EINE Legende oben je Reiter - Lesen (Kopierknoepfe), Technik (Vorlagen), Aktion. */ ?>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-lesen"></i> <?php echo bw_t('LEGENDE.LESEN'); ?></span>
  <span><i class="sm-punkt sm-b-technik"></i> <?php echo bw_t('LEGENDE.TECHNIK'); ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-hinweis"><?php echo bw_t('LOX.ERKL'); ?></div>

<div class="sm-step"><b>1. <?php echo bw_t('LOX.S1_T'); ?></b><br><?php echo bw_t('LOX.S1'); ?></div>

<?php
/* Gruppe 23 (2.5): die Namenstabelle - je gesendetes Thema der Name, den das MQTT-Gateway als Virtuellen Eingang
   vergibt (bw_gateway_name()), aus bw_mqtt_namenstabelle(): EINE Quelle fuer Sendeliste, Hakenliste und
   Bausteinliste. Die Kopierknoepfe lesen nur (gruen). */
$bw_nt = bw_mqtt_namenstabelle($bw_cfg);
?>
<div class="sm-step" id="eb-werte"><b>2. <?php echo bw_t('LOX.S2M_T'); ?></b><br><?php echo bw_t('LOX.S2M'); ?>
<?php if (empty($bw_cfg['mqtt_ein'])) { ?>
<div class="sm-warnung"><?php echo bw_t('LOX.MQTT_AUS'); ?></div>
<?php } ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('LOX.NT_NAME'); ?></th><th><?php echo bw_t('MQTT.THEMA'); ?></th><th><?php echo bw_t('LOX.NT_WERTE'); ?></th><th><?php echo bw_t('MQTT.BEDEUTUNG'); ?></th><th><?php echo bw_t('LOX.NT_NR'); ?></th><th></th></tr>
<?php foreach ($bw_nt as $bw_n) { ?>
  <tr><td class="sm-mono"><?= bw_e($bw_n['name']) ?></td>
      <td class="sm-mono"><?= bw_e($bw_n['voll']) ?></td>
      <td><?= bw_e($bw_n['bereich']) ?><?= $bw_n['retain'] ? ' &middot; ' . bw_e(bw_t('LOX.NT_RETAIN')) : '' ?></td>
      <td><?= bw_e(bw_t($bw_n['bez'])) ?></td>
      <td><?= $bw_n['nr'] > 0 ? '#' . (int) $bw_n['nr'] : '&ndash;' ?></td>
      <td><button data-role="none" type="button" class="sm-btn sm-b-lesen sm-kopie" style="min-width:0" data-kopie="<?= bw_e($bw_n['name']) ?>"><?php echo bw_t('LOX.K_KOPIE'); ?></button></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?php echo bw_t('LOX.NT_RECHTE'); ?></p>
<p class="sm-hilfe"><?php echo bw_t('LOX.NT_RECONNECT'); ?></p>
</div>

<?php
/* Gruppe 23 (V1, 3.1): die Befehle ueber die Befehlsbruecke - Tabelle aus bb_liste() (EINE Quelle fuer Reiter,
   Vorlage und Musterprojekt). */
list($bw_bl, $bw_bl_fehl) = bw_bruecke_liste();
?>
<div class="sm-step" id="eb-befehle"><b>3. <?php echo bw_t('BEFEHL.TITEL'); ?></b><br>
<?php if ($bw_bl === null) { ?>
<div class="sm-fehler"><?php echo bw_e(sprintf(bw_t('BEFEHL.NICHT_LADBAR'), $bw_bl_fehl)); ?></div>
<?php } else {
    $bw_bp = function_exists('bb_pfade') ? bb_pfade() : array();
    $bw_bport = $bw_bp ? bb_broker($bw_bp)['udpin'] : 11884; ?>
<?php echo bw_e(sprintf(bw_t('BEFEHL.TEXT'), (int) $bw_bport)); ?>
<div class="<?= (!empty($bw_cfg['befehle_mqtt_ein']) && !empty($bw_cfg['mqtt_ein'])) ? 'sm-hilfe' : 'sm-warnung' ?>"><?php echo bw_e(bw_t((!empty($bw_cfg['befehle_mqtt_ein']) && !empty($bw_cfg['mqtt_ein'])) ? 'BEFEHL.HAKEN_AN' : 'BEFEHL.HAKEN_AUS')); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo bw_t('BEFEHL.SP_TITEL'); ?></th><th><?php echo bw_t('BEFEHL.SP_THEMA'); ?></th><th><?php echo bw_t('BEFEHL.SP_BEFEHL'); ?></th><th><?php echo bw_t('BEFEHL.SP_SPERRE'); ?></th><th><?php echo bw_t('BEFEHL.SP_WIRKT'); ?></th></tr>
<?php foreach ($bw_bl['befehle'] as $bw_bb) { ?>
<tr><td><?= bw_e(bw_t($bw_bb['kachel'])) ?></td><td class="sm-mono"><?= bw_e($bw_bb['thema']) ?></td>
    <td class="sm-mono"><?= bw_e(isset($bw_bb['loxone']['ein']) ? $bw_bb['loxone']['ein'] : '') ?></td>
    <td class="sm-hilfe"><?= bw_e(sprintf(bw_t('BEFEHL.SPERRE'), (int) $bw_bb['sperre_s'])) ?></td>
    <td class="sm-hilfe"><?= bw_e(bw_t($bw_bb['eingreifend'] ? 'BEFEHL.WIRKT_JETZT' : 'BEFEHL.WIRKT_PRUEFEN')) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?php echo bw_e(sprintf(bw_t('BEFEHL.QUITTUNG_H'), $bw_bl['lebenszeichen'])); ?></p>
<p class="sm-hilfe"><?php echo bw_t('BEFEHL.RETAIN_NIE'); ?></p>
<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="befehle"><?php echo bw_t('BEFEHL.K_VORLAGE'); ?></button>
  </div>
</form>
<p class="sm-hilfe"><?php echo bw_t('BEFEHL.VORLAGE_H'); ?></p>
<?php } ?>
</div>

<?php
/* DIE BAUSTEIN-LISTE - seit Welle Bild 11 VOLL (0.9.30) die Seite "Beschattungswaechter" des LoxBerry-Plugins
 * Musterprojekts (Musterprojekt/baustein_listen.txt), eine Zeile = ein Baustein. SEIT 0.9.31 (Gruppe 23, Entwurf 9)
 * 35 Zeilen: die Eingaenge sind Virtuelle Eingaenge mit dem Namen, den das MQTT-Gateway vergibt (aus
 * bw_mqtt_namenstabelle() - ein anderes Praefix aendert die Namen mit), #5 eine Analogwertvalidierung am
 * Lebenszeichen status/zaehler (Tmc 1500 s, Konstante #33), #12/#32 Befehle am Ausgang "LoxBerry MQTT-Gateway" (Text aus
 * bb_liste()), #34 UND und #35 Einschaltverzoegerung gegen die Fehlalarme der Zaehlung alle 15 Minuten (N5, E-E18).
 * Verweise in den Texten als {Bn} werden zur laufenden Nummer "#n" gerechnet (Regeln/01); {L:ABSCHNITT.SCHLUESSEL}
 * nennt die Beschriftung der Oberflaeche. Zeile: array(Kennung, Typ, Name, Parameter, Verbindung) als HTML. */
$bw_bs_mono = function ($s) { return '<span class="sm-mono">' . bw_e($s) . '</span>'; };
$bw_bs_nt = array();
foreach ($bw_nt as $bw_n) { $bw_bs_nt[$bw_n['thema']] = $bw_n['name']; }
$bw_bs_vi = function ($thema) use ($bw_bs_nt, $bw_bs_mono) {
    return $bw_bs_mono(isset($bw_bs_nt[$thema]) ? $bw_bs_nt[$thema] : $thema);
};
$bw_bs_pf = bw_mqtt_praefix($bw_cfg['mqtt_thema']);
$bw_bs_bef = array('jetzt' => 'publish ' . $bw_bs_pf . '/befehl/jetzt 1', 'pruefen' => 'publish ' . $bw_bs_pf . '/befehl/pruefen 1');
$bw_bs_qt = array('jetzt' => $bw_bs_pf . '/befehl/jetzt/quittung', 'pruefen' => $bw_bs_pf . '/befehl/pruefen/quittung');
if ($bw_bl !== null) {
    foreach ($bw_bl['befehle'] as $bw_bb) {
        if (isset($bw_bs_bef[$bw_bb['name']], $bw_bb['loxone']['ein'])) {
            $bw_bs_bef[$bw_bb['name']] = $bw_bb['loxone']['ein'];
            $bw_bs_qt[$bw_bb['name']] = $bw_bb['thema'] . '/quittung';
        }
    }
}
$bw_vim = bw_t('BAUSTEIN.BT_VIM');
$bw_gwa = bw_t('BAUSTEIN.BT_GWA');
$bw_bs = array(
    array('B1', $bw_vim, $bw_bs_vi('status/zaehler'), bw_t('BAUSTEIN.B1_PARAM'), bw_t('BAUSTEIN.B1_VERB')),
    array('B2', $bw_vim, $bw_bs_vi('fehler'), bw_t('BAUSTEIN.B2_PARAM'), bw_t('BAUSTEIN.B2_VERB')),
    array('B3', $bw_vim, $bw_bs_vi('automatiken'), bw_t('BAUSTEIN.B3_PARAM'), bw_t('BAUSTEIN.B3_VERB')),
    array('B4', $bw_vim, $bw_bs_vi('scharf'), bw_t('BAUSTEIN.B4_PARAM'), bw_t('BAUSTEIN.B4_VERB')),
    array('B5', bw_t('BAUSTEIN.B5_TYP'), $bw_bs_mono('BW Waechter tot'), bw_t('BAUSTEIN.B5_PARAM'), bw_t('BAUSTEIN.B5_VERB')),
    array('B6', bw_t('BAUSTEIN.B6_TYP'), $bw_bs_mono('BW Waechter gestoert'), bw_t('BAUSTEIN.B6_PARAM'), bw_t('BAUSTEIN.B6_VERB')),
    array('B7', bw_t('BAUSTEIN.B7_TYP'), $bw_bs_mono('BW Automatik abgeschaltet'), bw_t('BAUSTEIN.B7_PARAM'), bw_t('BAUSTEIN.B7_VERB')),
    array('B8', bw_t('BAUSTEIN.B8_TYP'), $bw_bs_mono('BW Meldung 1 oder 2'), bw_t('BAUSTEIN.B8_PARAM'), bw_t('BAUSTEIN.B8_VERB')),
    array('B9', bw_t('BAUSTEIN.B9_TYP'), $bw_bs_mono('BW Meldung 1 bis 3'), bw_t('BAUSTEIN.B9_PARAM'), bw_t('BAUSTEIN.B9_VERB')),
    array('B10', bw_t('BAUSTEIN.B10_TYP'), $bw_bs_mono('BW Meldung'), bw_t('BAUSTEIN.B10_PARAM'), bw_t('BAUSTEIN.B10_VERB')),
    array('B11', bw_t('BAUSTEIN.B11_TYP'), $bw_bs_mono('BW Befehl jetzt'), bw_t('BAUSTEIN.B11_PARAM'), bw_t('BAUSTEIN.B11_VERB')),
    array('B12', $bw_gwa, $bw_bs_mono('BW Befehl jetzt senden'),
          bw_e(sprintf(bw_t('BAUSTEIN.B12_PARAM'), $bw_bs_bef['jetzt'], $bw_bs_qt['jetzt'])), bw_t('BAUSTEIN.B12_VERB')),
    array('B13', $bw_vim, $bw_bs_vi('status/ok'), bw_t('BAUSTEIN.B13_PARAM'), bw_t('BAUSTEIN.B13_VERB')),
    array('B14', $bw_vim, $bw_bs_vi('aktiv'), bw_t('BAUSTEIN.B14_PARAM'), bw_t('BAUSTEIN.B14_VERB')),
    array('B15', $bw_vim, $bw_bs_vi('fenster'), bw_t('BAUSTEIN.B15_PARAM'), bw_t('BAUSTEIN.B15_VERB')),
    array('B16', $bw_vim, $bw_bs_vi('ziele'), bw_t('BAUSTEIN.B16_PARAM'), bw_t('BAUSTEIN.B16_VERB')),
    array('B17', bw_t('BAUSTEIN.B17_TYP'), $bw_bs_mono('BW Zustand'), bw_t('BAUSTEIN.B17_PARAM'), bw_t('BAUSTEIN.B17_VERB')),
    array('B18', $bw_vim, $bw_bs_vi('gesendet'), bw_t('BAUSTEIN.B18_PARAM'), bw_t('BAUSTEIN.B18_VERB')),
    array('B19', $bw_vim, $bw_bs_vi('code'), bw_t('BAUSTEIN.B19_PARAM'), bw_t('BAUSTEIN.B19_VERB')),
    array('B20', $bw_vim, $bw_bs_vi('alter'), bw_t('BAUSTEIN.B20_PARAM'), bw_t('BAUSTEIN.B20_VERB')),
    array('B21', bw_t('BAUSTEIN.B21_TYP'), $bw_bs_mono('BW Minuten seit Befehl'), bw_t('BAUSTEIN.B21_PARAM'), bw_t('BAUSTEIN.B21_VERB')),
    array('B22', bw_t('BAUSTEIN.B22_TYP'), $bw_bs_mono('BW Letzter Befehl'), bw_t('BAUSTEIN.B22_PARAM'), bw_t('BAUSTEIN.B22_VERB')),
    array('B23', bw_t('BAUSTEIN.B23_TYP'), $bw_bs_mono('BW Automatiken'), bw_t('BAUSTEIN.B23_PARAM'), bw_t('BAUSTEIN.B23_VERB')),
    array('B24', bw_t('BAUSTEIN.B24_TYP'), $bw_bs_mono('BW kein Ziel'), bw_t('BAUSTEIN.B24_PARAM'), bw_t('BAUSTEIN.B24_VERB')),
    array('B25', bw_t('BAUSTEIN.B25_TYP'), $bw_bs_mono("BW ohne Ziel h\u{e4}lt an"), bw_t('BAUSTEIN.B25_PARAM'), bw_t('BAUSTEIN.B25_VERB')),
    array('B26', bw_t('BAUSTEIN.B26_TYP'), $bw_bs_mono('BW ohne Ziel'), bw_t('BAUSTEIN.B26_PARAM'), bw_t('BAUSTEIN.B26_VERB')),
    array('B27', bw_t('BAUSTEIN.B27_TYP'), $bw_bs_mono('Beschattungsfreigabe (eigene Quelle)'), bw_t('BAUSTEIN.B27_PARAM'), bw_t('BAUSTEIN.B27_VERB')),
    array('B28', bw_t('BAUSTEIN.B28_TYP'), $bw_bs_mono('BW Freigabe beruhigt'), bw_t('BAUSTEIN.B28_PARAM'), bw_t('BAUSTEIN.B28_VERB')),
    array('B29', bw_t('BAUSTEIN.B29_TYP'), $bw_bs_mono('BW Beschattung beginnt'), bw_t('BAUSTEIN.B29_PARAM'), bw_t('BAUSTEIN.B29_VERB')),
    array('B30', bw_t('BAUSTEIN.B30_TYP'), $bw_bs_mono("BW Befehl jetzt ausl\u{f6}sen"), bw_t('BAUSTEIN.B30_PARAM'), bw_t('BAUSTEIN.B30_VERB')),
    array('B31', bw_t('BAUSTEIN.B31_TYP'), $bw_bs_mono("BW Automatiken jetzt z\u{e4}hlen"), bw_t('BAUSTEIN.B31_PARAM'), bw_t('BAUSTEIN.B31_VERB')),
    array('B32', $bw_gwa, $bw_bs_mono("BW Automatiken z\u{e4}hlen"),
          bw_e(sprintf(bw_t('BAUSTEIN.B32_PARAM'), $bw_bs_bef['pruefen'], $bw_bs_qt['pruefen'])), bw_t('BAUSTEIN.B32_VERB')),
    array('B33', bw_t('BAUSTEIN.B33_TYP'), $bw_bs_mono('Konstante 1'), bw_t('BAUSTEIN.B33_PARAM'), bw_t('BAUSTEIN.B33_VERB')),
    array('B34', bw_t('BAUSTEIN.B34_TYP'), $bw_bs_mono('BW abgeschaltet im Zeitfenster'), bw_t('BAUSTEIN.B34_PARAM'), bw_t('BAUSTEIN.B34_VERB')),
    array('B35', bw_t('BAUSTEIN.B35_TYP'), $bw_bs_mono('BW Automatik bleibt abgeschaltet'), bw_t('BAUSTEIN.B35_PARAM'), bw_t('BAUSTEIN.B35_VERB')),
);
$bw_bs_nr = array();
foreach ($bw_bs as $bw_i => $bw_z) { $bw_bs_nr[$bw_z[0]] = $bw_i + 1; }
$bw_bs_r = function ($s) use ($bw_bs_nr) {
    return preg_replace_callback('/\{(B\d+|L:[A-Z]+\.[A-Z0-9_]+)\}/', function ($m) use ($bw_bs_nr) {
        if (isset($bw_bs_nr[$m[1]])) { return '#' . $bw_bs_nr[$m[1]]; }
        if (strncmp($m[1], 'L:', 2) === 0) { return bw_e(bw_t(substr($m[1], 2))); }
        return $m[0];
    }, (string) $s);
};
$bw_bs_schritte = array('A_S1', 'A_S2', 'A_S3', 'A_S4', 'A_S5', 'A_S6', 'A_S7', 'A_S8', 'A_S9', 'A_S10', 'A_S11');
$bw_bs_hinweise = array('ERLAEUTERUNG', 'H_GROESSER', 'H_START', 'H_JETZT', 'H_HAND', 'H_SPERRE', 'H_ALLE', 'H_DESIGNER');
?>
<div class="sm-step"><b>4. <?php echo bw_t('BAUSTEIN.H'); ?></b><br><?php echo $bw_bs_r(bw_t('BAUSTEIN.TEXT')); ?>
<h3><?php echo bw_e(bw_t('BAUSTEIN.UE_TITEL')); ?></h3>
<div class="sm-hinweis"><b><?php echo bw_e(bw_t('BAUSTEIN.SO_TITEL')); ?></b>
<ol>
<?php foreach ($bw_bs_schritte as $bw_k) { ?>
<li><?= $bw_bs_r(bw_t('BAUSTEIN.' . $bw_k)) ?></li>
<?php } ?>
</ol>
</div>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?php echo bw_t('BAUSTEIN.T_TYP'); ?></th><th><?php echo bw_t('BAUSTEIN.T_NAME'); ?></th><th><?php echo bw_t('BAUSTEIN.T_PARAM'); ?></th><th><?php echo bw_t('BAUSTEIN.T_VERB'); ?></th></tr>
<?php foreach ($bw_bs as $bw_i => $bw_z) { ?>
  <tr><td><?= (int) $bw_i + 1 ?></td><td><?= $bw_bs_r($bw_z[1]) ?></td><td><?= $bw_bs_r($bw_z[2]) ?></td>
      <td><?= $bw_bs_r($bw_z[3]) ?></td><td><?= $bw_bs_r($bw_z[4]) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><b><?php echo bw_e(bw_t('BAUSTEIN.H_TITEL')); ?></b></p>
<ul class="sm-hilfe">
<?php foreach ($bw_bs_hinweise as $bw_k) { ?>
<li><?= $bw_bs_r(bw_t('BAUSTEIN.' . $bw_k)) ?></li>
<?php } ?>
</ul>
<figure class="sm-bild">
<img src="einbindung_loxone.png" alt="<?= bw_e(bw_t('LOX.BILD_ALT')) ?>" loading="lazy">
<figcaption><?= bw_e(bw_t('LOX.BILD_UNTERSCHRIFT')) ?></figcaption>
</figure>
<p class="sm-hilfe"><?= bw_t('LOX.MUSTERPROJEKT') ?></p>
</div>

<?php
/* Gruppe 23 (3.2, E-E13): der alte HTTP-Weg - zugeklappt, offen bei endpunkt_ein = 1. Der Lesezweig (status, json)
   haengt am Haken endpunkt_ein (eigenes Formular, orange); jetzt/pruefen mit Merkwort wirken unabhaengig davon. */
$bw_ep_an = !empty($bw_cfg['endpunkt_ein']);
?>
<details class="sm-step" id="eb-http"<?= $bw_ep_an ? ' open' : '' ?>>
<summary><b>5. <?php echo bw_t('LOX.HTTP_T'); ?></b></summary>
<div class="sm-hinweis"><?php echo bw_t('LOX.HTTP_HINWEIS'); ?></div>
<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <div class="sm-feld">
    <label><input data-role="none" type="checkbox" name="endpunkt_ein" value="1"<?= $bw_ep_an ? ' checked' : '' ?>>
      <?php echo bw_t('LOX.L_ENDPUNKT'); ?></label>
    <p class="sm-hilfe"><?php echo bw_t('LOX.H_ENDPUNKT'); ?></p>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_endpunkt" value="1"><?php echo bw_t('TEXT.SPEICHERN'); ?></button>
  </div>
</form>

<h3><?php echo bw_t('LOX.S2_T'); ?></h3>
<p><?php echo bw_t('LOX.S2'); ?></p>
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
</form>

<h3><?php echo bw_t('LOX.S3_T'); ?></h3>
<p><?php echo bw_t('LOX.S3'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo bw_t('LOX.FELD'); ?></th><th><?php echo bw_t('LOX.CHECK'); ?></th><th><?php echo bw_t('LOX.EINHEIT'); ?></th><th><?php echo bw_t('LOX.BEDEUTUNG'); ?></th></tr>
<?php
/* Der Suchtext kommt aus bw_check() - EINE Funktion, dieselbe, die den
   Endpunkt beschriftet. */
foreach (bw_felder() as $bw_fn => $bw_fi) { ?>
  <tr><td class="sm-mono">BW <?= bw_e($bw_fn) ?></td>
      <td class="sm-mono"><?= bw_e(bw_check($bw_fn)) ?></td>
      <td><?= bw_e($bw_fi['einheit']) ?></td>
      <td><?= bw_e(bw_t($bw_fi['bez'])) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?php echo bw_t('LOX.CHECK_HILFE'); ?></p>
<h3><?php echo bw_t('LOX.S4_T'); ?></h3>
<p><?php echo bw_t('LOX.S4'); ?></p>
<h3><?php echo bw_t('LOX.S6_T'); ?></h3>
<p><?php echo bw_t('LOX.S6'); ?></p>
<div class="sm-warnung"><?php echo bw_t('LOX.IMPORT_WARNUNG'); ?></div>
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
</details>

<div class="sm-step"><b>6. <?php echo bw_t('LOX.S7_T'); ?></b><br><?php echo bw_t('LOX.S7'); ?></div>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo bw_t('TEXT.H_TEST'); ?></h2>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-lesen"></i> <?php echo bw_t('LEGENDE.LESEN'); ?></span>
  <span><i class="sm-punkt sm-b-technik"></i> <?php echo bw_t('LEGENDE.TECHNIK'); ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span>
</div>

<div class="sm-kacheln">
  <div class="sm-kachel"><b><?= empty($bw_stand['letzte']) ? '&mdash;' : bw_e(bw_zeitpunkt($bw_stand['letzte'])) ?></b><span><?php echo bw_t('TEXT.K_LETZTE'); ?></span><?php
    /* b1: die gemessene Wirkung des letzten Befehls - Position danach
       geaendert, und wann (bw_wirkung_text()). */
    $bw_wtext = bw_wirkung_text($bw_cfg, $bw_stand);
    if ($bw_wtext !== '') { echo '<span class="sm-wirkung">' . bw_e($bw_wtext) . '</span>'; } ?></div>
  <!-- letzte_ok wurde bis 0.9.12 an drei Stellen GESCHRIEBEN und an keiner
       gelesen. Der Unterschied zwischen "zuletzt versucht" und "zuletzt
       angenommen" ist genau das, was man beim Suchen wissen will. -->
  <div class="sm-kachel"><b><?= empty($bw_stand['letzte_ok']) ? '&mdash;' : bw_e(bw_zeitpunkt($bw_stand['letzte_ok'])) ?></b><span><?php echo bw_t('TEXT.K_LETZTE_OK'); ?></span></div>
  <div class="sm-kachel"><b><?= (int) (isset($bw_stand['gesendet']) ? $bw_stand['gesendet'] : 0) ?></b><span><?php echo bw_t('TEXT.K_GESENDET'); ?></span></div>
  <div class="sm-kachel"><b><?= (int) (isset($bw_stand['fehler']) ? $bw_stand['fehler'] : 0) ?></b><span><?php echo bw_t('TEXT.K_FEHLER'); ?></span></div>
  <div class="sm-kachel"><b><?= bw_im_fenster($bw_cfg) ? bw_t('TEXT.JA') : bw_t('TEXT.NEIN') ?></b><span><?php echo bw_t('TEXT.K_FENSTER'); ?></span></div>
</div>

<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-test">
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
      <td class="sm-mono"><?= bw_e(bw_gekuerzt(isset($bw_r['meldung']) ? $bw_r['meldung'] : $bw_r['text'], 120)) ?></td>
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

/* ---- Pflichtzeilen des Hausstandards (O3, Durchgang 30.09.2026) ---------
   Bis 0.9.21 stand hier kein Befund, kein Lebenszeichen, kein echter Aufruf
   des Endpunkts und kein Vergleich der Themenliste mit dem Sendecode - ein
   seit drei Stunden stehender Takt ergab 0 rote Zeilen, waehrend der
   Healthcheck derselben Lage Stufe 3 meldete (Pruefbericht Oberflaeche O3). */

/* 1. Der Befund - DIESELBE Funktion wie Healthcheck und Benachrichtigung.
   Stufe 5 ist ein Haken, 6 (abgeschaltet, Update laeuft) ein Strich, 3 und
   4 ein Befund. */
list($bw_bst, $bw_btx) = bw_befund($bw_cfg);
bw_zeile($bw_selbst, bw_t('TEXT.S_BEFUND'), $bw_bst === 5 ? 1 : ($bw_bst === 6 ? 2 : 0), $bw_btx);

/* 2. Das Lebenszeichen des Takts: wie alt ist der letzte Durchgang? Dieselbe
   Grenze wie OK am Endpunkt. Vor dem ersten Durchgang gibt es nichts zu
   beurteilen - ein Strich, kein Kreuz. */
$bw_lz = bw_lauf_lesen();
if ((int) $bw_lz['ts'] === 0) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_TAKT'), 2, bw_t('TEXT.S_TAKT_NIE'));
} else {
    $bw_lza = time() - (int) $bw_lz['ts'];
    bw_zeile($bw_selbst, bw_t('TEXT.S_TAKT'), ($bw_lza >= 0 && $bw_lza <= bw_ok_grenze()) ? 1 : 0,
        sprintf(bw_t('TEXT.S_TAKT_ALTER'), $bw_lza, bw_ok_grenze(), (int) $bw_lz['zaehler']));
}

/* 3. Der Endpunkt, WIRKLICH aufgerufen ueber 127.0.0.1 - nur wenn der Reiter
   Test der serverseitig offene ist (Regeln/04): alle Reiter stehen in einem
   Dokument, und der Aufruf kostet bis zu drei Sekunden. */
$bw_eptok = trim((string) $bw_cfg['aktionstoken']);
if ($bw_eptok === '') {
    bw_zeile($bw_selbst, bw_t('TEXT.S_EP_RUF'), 2, bw_t('TEXT.S_KEIN_TOKEN'));
} elseif (!$bw_test_offen) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_EP_RUF'), 2, bw_t('TEXT.S_EP_NUR_TEST'));
} else {
    $bw_epr = bw_endpunkt_probe($bw_eptok);
    if ($bw_epr['lage'] === 'gut') {
        bw_zeile($bw_selbst, bw_t('TEXT.S_EP_RUF'), 1, bw_t('TEXT.S_EP_GUT'));
    } elseif ($bw_epr['lage'] === 'falsch') {
        bw_zeile($bw_selbst, bw_t('TEXT.S_EP_RUF'), 0,
            sprintf(bw_t('TEXT.S_EP_FALSCH'), (int) $bw_epr['code'], $bw_epr['anfang']));
    } else {
        bw_zeile($bw_selbst, bw_t('TEXT.S_EP_RUF'), 2, bw_t('TEXT.S_EP_STUMM'));
    }
}

/* 3b (Gruppe 23, E-E13): der HTTP-Lesezweig (status, json) haengt am Haken endpunkt_ein - aus ist gewollt (grau). */
bw_zeile($bw_selbst, bw_t('TEXT.S_LESEZWEIG'), !empty($bw_cfg['endpunkt_ein']) ? 1 : 2,
    bw_t(!empty($bw_cfg['endpunkt_ein']) ? 'TEXT.S_LESEZWEIG_AN' : 'TEXT.S_LESEZWEIG_AUS'));

/* 4. Die Themenliste gegen den Sendecode, in beide Richtungen - seit 0.9.31 mit der Namenstabelle. */
list($bw_thok, $bw_thl, $bw_ths, $bw_thnl, $bw_thns) = bw_mqtt_themen_pruefen($bw_cfg);
bw_zeile($bw_selbst, bw_t('TEXT.S_THEMEN'), $bw_thok ? 1 : 0,
    $bw_thok ? sprintf(bw_t('TEXT.S_THEMEN_OK'), $bw_thl, $bw_ths)
             : trim(($bw_thnl ? bw_t('TEXT.S_THEMEN_NUR_LISTE') . ' ' . implode(', ', $bw_thnl) . ' ' : '')
                  . ($bw_thns ? bw_t('TEXT.S_THEMEN_NUR_SENDEN') . ' ' . implode(', ', $bw_thns) : '')));

/* 5. Die Vorlagen fuer Loxone Config: wohlgeformt? */
$bw_vl = bw_vorlagen_pruefen($bw_cfg);
if ($bw_vl === null) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_VORLAGEN'), 2, bw_t('TEXT.S_VORLAGEN_OHNE'));
} else {
    $bw_vlk = array();
    $bw_vlt = array();
    foreach ($bw_vl as $bw_vn => $bw_vz) {
        if ($bw_vz < 0) { $bw_vlk[] = $bw_vn; }
        $bw_vlt[] = sprintf(bw_t('TEXT.S_VORLAGEN_OK'), $bw_vn, $bw_vz);
    }
    bw_zeile($bw_selbst, bw_t('TEXT.S_VORLAGEN'), $bw_vlk ? 0 : 1,
        $bw_vlk ? bw_t('TEXT.S_VORLAGEN_KAPUTT') . ' ' . implode(', ', $bw_vlk) : implode('; ', $bw_vlt));
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
/* O4, Durchgang 30.09.2026: gemeldet wird der ERSTE Zustand dieses Aufrufs,
   nicht der nach der Selbstheilung. Rot, wenn die Datei unlesbar war - auch
   wenn die Heilung gegriffen hat - und solange eine .kaputt-Datei daneben
   liegt: dann ist einmal etwas verloren gegangen, und die Zweitschrift kann
   aelter sein als das Verlorene. */
$bw_lage1 = bw_config_lage(null, true);
$bw_kaputtdatei = bw_paths()['cfgdatei'] . '.kaputt';
if ($bw_lage1['lage'] === 'kaputt') {
    bw_zeile($bw_selbst, bw_t('TEXT.S_KONFIG'), 0, bw_t('TEXT.LAGE_KAPUTT'));
} elseif (!empty($bw_lage1['war_kaputt'])) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_KONFIG'), 0,
        sprintf(bw_t('TEXT.LAGE_KAPUTT_GEHEILT'), basename($bw_kaputtdatei)));
} elseif (is_file($bw_kaputtdatei)) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_KONFIG'), 0,
        sprintf(bw_t('TEXT.LAGE_KAPUTT_LIEGT'), basename($bw_kaputtdatei)));
} else {
    bw_zeile($bw_selbst, bw_t('TEXT.S_KONFIG'),
        in_array($bw_lage1['lage'], array('ok', 'leer', 'neu'), true) ? 1
            : ($bw_lage1['lage'] === 'aus_zweitschrift' ? 2 : 0),
        bw_t('TEXT.LAGE_' . strtoupper($bw_lage1['lage'])));
}

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
/* Im Archiv liegt der Lauf unter <archiv>/bin/. Bis 0.9.19 wurde auch
   dort vier Stufen hinauf gerechnet, also AUSSERHALB des Archivs (Fall T13). */
$bw_bin = (basename(dirname(__DIR__)) === 'plugins')
    ? dirname(dirname(dirname(dirname(__DIR__)))) . '/bin/plugins/' . bw_paths()['plugin'] . '/bw_lauf.php'
    : dirname(dirname(__DIR__)) . '/bin/bw_lauf.php';
$bw_binenv = getenv('LBPBINDIR');
if ($bw_binenv !== false && $bw_binenv !== '') { $bw_bin = $bw_binenv . '/bw_lauf.php'; }
bw_zeile($bw_selbst, bw_t('TEXT.S_LAUF'), is_file($bw_bin) ? 1 : 0,
    is_file($bw_bin) ? $bw_bin : bw_t('TEXT.S_NICHT_GEFUNDEN'));

/* Der GEWAEHLTE Miniserver (O2) - nicht die Anzahl. Bis 0.9.21 stand hier
   "in Ordnung (2 x)", waehrend der eingestellte fehlte. */
if (!$bw_ms) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_MS'), 0, bw_t('TEXT.KEIN_MS'));
} elseif ($bw_gewaehlt === null) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_MS'), 0, sprintf(bw_t('TEXT.MS_FEHLT_KURZ'), $bw_ms_nr));
} else {
    bw_zeile($bw_selbst, bw_t('TEXT.S_MS'), 1,
        $bw_gewaehlt['name'] . ' (' . $bw_gewaehlt['adresse'] . ')');
}

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
/* Dieselbe Lageregel wie oben: im Archiv der eigene Endpunkt. Bis 0.9.19
   zuerst drei Stufen hinauf, also ein Ort ausserhalb des Archivs (Fall T12). */
$bw_ep = (basename(dirname(__DIR__)) === 'plugins')
    ? dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . bw_paths()['plugin'] . '/index.php'
    : dirname(__DIR__) . '/html/index.php';
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

/* Die Wetterquelle (Wetter-1, ab Werk aus). Im Reiter Test ein ECHTER
   Abruf ueber 127.0.0.1 - nur, wenn er serverseitig offen ist (wie der
   Endpunkt oben); sonst der Stand des letzten Takts. Dieselbe Rechnung wie
   der Takt (bw_wetter_bewerten()). Ein Strich, solange noch nie gefragt
   wurde; ein Kreuz, wenn die Weiche keinen frischen Wert liefert - dann
   drueckt der Takt ohne Wetterbedingung, und genau das steht dabei. */
if (empty($bw_cfg['wetter_ein'])) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_WETTER'), 2, bw_t('TEXT.S_WETTER_AUS'));
} else {
    $bw_wd = bw_wetter_lesen();
    $bw_wjetzt = time();
    if ($bw_test_offen) {
        $bw_wd = bw_wetter_eintragen($bw_wd, bw_wetter_holen($bw_cfg), $bw_wjetzt);
    }
    if (!isset($bw_wd['abruf']) || !is_array($bw_wd['abruf'])) {
        bw_zeile($bw_selbst, bw_t('TEXT.S_WETTER'), 2, bw_t('TEXT.S_WETTER_NIE'));
    } else {
        $bw_we = bw_wetter_bewerten($bw_cfg, $bw_wd, $bw_wjetzt);
        $bw_wgrund = (string) $bw_wd['abruf']['grund'];
        $bw_wgtext = ($bw_wgrund === '') ? ''
            : sprintf(bw_t('TEXT.WETTER_G_' . $bw_wgrund), (int) $bw_wd['abruf']['code']);
        $bw_wzeit = bw_zeitpunkt((int) $bw_wd['abruf']['ts']);
        if (!empty($bw_wd['abruf']['ok'])) {
            bw_zeile($bw_selbst, bw_t('TEXT.S_WETTER'), 1,
                sprintf(bw_t('TEXT.S_WETTER_GUT'), $bw_wzeit, bw_wetter_zahl($bw_we['sonne']),
                        bw_wetter_zahl($bw_we['wind']), bw_wetter_satz($bw_we)));
        } elseif ($bw_we['lage'] === 'alt') {
            bw_zeile($bw_selbst, bw_t('TEXT.S_WETTER'), 2,
                sprintf(bw_t('TEXT.S_WETTER_ALT'), $bw_wzeit, $bw_wgtext, (int) $bw_we['alter'],
                        bw_wetter_satz($bw_we)));
        } else {
            bw_zeile($bw_selbst, bw_t('TEXT.S_WETTER'), 0,
                sprintf(bw_t('TEXT.S_WETTER_AUSGEFALLEN'), $bw_wzeit, $bw_wgtext,
                        (int) round(bw_wetter_frist() / 60)));
        }
    }
}

/* Der Sonnenstand der Fensterbilanz (Sonne-1, ab Werk aus). Gelesen wird der
   Satz, den das Mithoeren des Takts zuletzt abgelegt hat - dieselbe Rechnung
   wie der Takt (bw_sonne_bewerten(), bw_sonne_ziele()). Ein Kreuz, wenn kein
   frischer Satz da ist: dann drueckt der Takt wie bisher, und genau das steht
   dabei. Die zweite Zeile sagt je Ziel, was der Takt jetzt taete. */
foreach (bw_sonne_pruefzeilen($bw_cfg) as $bw_sz) {
    bw_zeile($bw_selbst, $bw_sz[0], $bw_sz[1], $bw_sz[2]);
}

/* Gruppe 23 (V3): die Anwesenheit - dieselbe Rechnung wie der Takt (bw_anwesend_lage()). */
$bw_az = bw_anwesend_pruefzeile($bw_cfg, $bw_stand);
bw_zeile($bw_selbst, $bw_az[0], $bw_az[1], $bw_az[2]);

/* Gruppe 23 (V1): die Befehlsbruecke - Dienst, Zustand, Abo und letzter Befehl (bw_bruecke_lage()). */
list($bw_bok, $bw_btext) = bw_bruecke_lage();
bw_zeile($bw_selbst, bw_t('BEFEHL.T_H'), $bw_bok === true ? 1 : ($bw_bok === null ? 2 : 0), $bw_btext);

/* Gruppe 23 (B15/N10): laesst sich lauf.lock oeffnen? */
bw_zeile($bw_selbst, bw_t('TEXT.S_SPERRE'), bw_sperrdatei_lage() === '' ? 1 : 0,
    bw_sperrdatei_lage() === '' ? bw_t('TEXT.S_SPERRE_OK') : sprintf(bw_t('TEXT.S_SPERRE_RECHTE'), bw_paths()['datadir'] . '/lauf.lock'));

/* Gruppe 23 (B18): eine liegengebliebene Upgrade-Marke (aelter als 3600 s oder weiter als 300 s in der Zukunft). */
$bw_mk = bw_marke_pfad();
if ($bw_mk !== '' && is_file($bw_mk)) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_MARKE'), bw_marke_gilt($bw_mk) ? 2 : 0,
        bw_marke_gilt($bw_mk) ? bw_t('TEXT.S_MARKE_GILT') : sprintf(bw_t('TEXT.S_MARKE_ALT'), $bw_mk));
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
if (is_file($bw_zw) && bw_zweitschrift_mit_inhalt() !== null) {
    bw_zeile($bw_selbst, bw_t('TEXT.S_ZWEIT'), 1,
        date('Y-m-d H:i', (int) @filemtime($bw_zw)));
} elseif (is_file($bw_zw)) {
    /* O14, Durchgang 30.09.2026: vorhanden, aber ohne Kennung und Merkwort
       (etwa {}) - kein Haken. Rot, wenn die Konfiguration selbst Inhalt
       traegt: dann sichert die Zweitschrift nichts von dem, was verloren
       gehen kann; sonst ein Strich. */
    bw_zeile($bw_selbst, bw_t('TEXT.S_ZWEIT'), bw_hat_inhalt(bw_config(false)) ? 0 : 2,
        sprintf(bw_t('TEXT.S_ZWEIT_LEER'), date('Y-m-d H:i', (int) @filemtime($bw_zw))));
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
  <div class="sm-feld">
    <label><input data-role="none" type="checkbox" name="rest_bestaetigt" value="1">
      <?php echo bw_t('TEXT.REST_HAKEN'); ?></label>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="rest_weg" value="1"><?php echo bw_t('TEXT.REST_WEG'); ?></button>
  </div>
</form>
<?php } ?>

<h3><?php echo bw_t('TEXT.H_ENDPUNKT'); ?></h3>
<div class="sm-hinweis"><?php echo bw_t('TEXT.H_ENDPUNKT_ERKL'); ?></div>
<?php if ($bw_tok === '') { ?>
<div class="sm-warnung"><?php echo bw_t('TEXT.S_KEIN_TOKEN'); ?></div>
<?php } else { ?>
<p class="sm-mono">http://<?= bw_e(bw_wirtsname()) ?><?= bw_e(bw_endpunkt_pfad(array('token' => $bw_tok, 'selftest' => '1'))) ?></p>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_ENDPUNKT_SELBST'); ?></p>
<?php if (!empty($bw_cfg['endpunkt_ein'])) { /* Gruppe 23: die Lesesaetze nur bei eingeschaltetem Lesezweig. */ ?>
<p class="sm-mono">http://<?= bw_e(bw_wirtsname()) ?><?= bw_e(bw_endpunkt_pfad(array('token' => $bw_tok, 'aktion' => 'status'))) ?></p>
<p class="sm-mono">http://<?= bw_e(bw_wirtsname()) ?><?= bw_e(bw_endpunkt_pfad(array('token' => $bw_tok, 'aktion' => 'json'))) ?></p>
<?php } else { ?>
<p class="sm-grau"><?php echo bw_t('TEXT.S_LESEZWEIG_AUS'); ?></p>
<?php } ?>
<?php } ?>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo bw_t('TEXT.H_PROTOKOLL'); ?></h2>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span></div>
<?php
/* E1 + B6b (Gruppe 23, wie Docker 1.3.19): das eigene Protokoll, die letzten 80 Zeilen, neueste oben - rueckwaerts
   gelesen (bw_log_ende()). Der Zweig ueber die Logliste von LBWeb ist weg: die Funktion GIBT ihre Liste zurueck und
   gibt nichts aus, der Rueckgabewert wurde verworfen - die Liste erschien nie, und mit display_errors=1 verdraengte
   eine Warnung das eigene Protokoll (Pruefbericht B6a/E1). beschattung.log ist eine schlichte Datei, nicht ueber
   LoxBerry::Log angemeldet - die Liste waere ohnehin leer. */
$bw_zeilen = array_reverse(bw_log_ende(bw_paths()['logdatei'], 80));
if ($bw_zeilen) {
    echo '<div class="sm-log">' . bw_e(implode("\n", $bw_zeilen)) . '</div>';
} else {
    echo '<p class="sm-grau">' . bw_e(bw_t('TEXT.PROTOKOLL_LEER')) . '</p>';
}
?>
<form action="index.php" method="post">
  <?php echo bw_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="protokoll_leeren" value="1"><?php echo bw_t('TEXT.K_LEEREN'); ?></button>
  </div>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_LEEREN'); ?></p>
</form>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_PROTOKOLL_HILFE'); ?></p>
<?php
/* Die Fehlerausgabe des Cron-Laufs (C5, Durchgang 30.09.2026). LoxBerry ruft
   jede Cron-Datei mit "> /dev/null 2>&1" auf; seit 0.9.22 lenkt
   cron/cron.05min die Fehlerausgabe in cron.err im Protokollordner. Gezeigt
   wird sie, sobald etwas darin steht - ein Abbruch mit fehlender Bibliothek
   oder ein Fatal Error ist sonst nirgends zu sehen. */
$bw_cronerr = bw_paths()['log'] . '/cron.err';
$bw_cez = bw_log_ende($bw_cronerr, 20);
if ($bw_cez) { ?>
<div class="sm-warnung"><?= sprintf(bw_t('TEXT.CRONERR'), bw_e($bw_cronerr)) ?></div>
<div class="sm-log"><?= bw_e(implode("\n", $bw_cez)) ?></div>
<?php } ?>
<div class="sm-warnung"><?php echo bw_t('TEXT.H_PROTOKOLL_RAMDISK'); ?></div>
</div>

</div>
<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		/* B1 (Gruppe 23, wie Docker D22): der Reiter Test laedt die Seite neu (index.php?form=test) - gemessen wird
		   nur dort; die uebrigen Reiter schalten ohne Seitenwechsel um. */
		r.addEventListener('click', function (e) {
			if (r.dataset.ziel === 'tab-test') { return; }
			e.preventDefault(); zeige(r.dataset.ziel);
		});
	});
	/* Gruppe 23: Kopierknopf der Namenstabelle (liest nur). */
	document.querySelectorAll('.sm-kopie').forEach(function (k) {
		k.addEventListener('click', function () {
			var t = k.getAttribute('data-kopie') || '';
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t); }
			else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select();
			       try { document.execCommand('copy'); } catch (x) {} document.body.removeChild(a); }
		});
	});
	/* Gruppe 23 (V4): die angekreuzten Bausteine NUR ins Formular uebernehmen (Ziel 1 ... in Reihenfolge, Befehl auto,
	   Fassaden leer, hoechstens sechs); gespeichert wird erst mit "Einstellungen speichern". */
	var ueb = document.getElementById('bw-uebernehmen');
	if (ueb) {
		ueb.addEventListener('click', function () {
			var wahl = [];
			document.querySelectorAll('.bw-wahl').forEach(function (w) { if (w.checked && wahl.length < 6) { wahl.push(w.value); } });
			for (var i = 1; i <= 6; i++) {
				var s = (i === 1) ? '' : String(i);
				var u = document.querySelector('input[name="uuid' + s + '"]');
				var b = document.querySelector('input[name="befehl' + s + '"]');
				var f = document.querySelector('input[name="fassade' + s + '"]');
				if (i <= wahl.length) {
					if (u) { u.value = wahl[i - 1]; }
					if (b) { b.value = 'auto'; }
					if (f) { f.value = ''; }
				} else if (u) { u.value = ''; }
			}
		});
	}
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($bw_tab) ?>);
})();
</script>
<?php
if ($bw_rahmen) {
    LBWeb::lbfooter();
}
