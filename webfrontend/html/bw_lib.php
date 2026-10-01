<?php
/**
 * Beschattungswaechter - gemeinsamer Unterbau
 *
 * WARUM ES DIESES PLUGIN GIBT
 * ---------------------------
 * In Loxone laesst sich die Sonnenstandsautomatik eines Rollladens aus der
 * Logik heraus NICHT einschalten. Das "A" in der App ist ein Befehl
 * (autoshade/1 am Rollladen, auto am Zentralbaustein), kein Eingang am
 * Baustein. Es gibt Sps (Start), DisSp (Sperre) und Spr (Reaktivierung) -
 * aber keinen Eingang, der die abgeschaltete Automatik zurueckholt.
 *
 * Am 24.08.2026 in diesem Haus nachgemessen: Sps = Ein, DisSp = Aus, ein
 * frischer Spr-Impuls um 12:10 - und um 12:13 immer noch 0 von 25
 * Automatiken scharf. Ein Druck auf das "A" um 13:48: sofort sechs Rollladen
 * auf 80 Prozent.
 *
 * ABGESCHALTET WIRD SIE JEDEN MORGEN von der Schaltuhr selbst: die faehrt die
 * Rollladen ueber den Eingang Co (Complete open) hoch, und eine Bedienung
 * ueber Co gilt fuer Loxone als Handbedienung - die schaltet die
 * Sonnenstandsautomatik fuer den Rest des Tages ab. Das Haus schaltet sich
 * also taeglich seine eigene Beschattung aus.
 *
 * Dieses Plugin drueckt das "A" von aussen, in einem einstellbaren Abstand,
 * innerhalb eines einstellbaren Zeitfensters. Der Befehl ist folgenlos, wenn
 * die Automatik schon an ist.
 *
 * (c) Beschattungswaechter Plugin Authors - MIT-Lizenz
 */

/**
 * Die LoxBerry-Wurzel finden, ohne einen Systempfad hinzuschreiben.
 *
 * Aufwaerts suchen, bis ein Verzeichnis gefunden ist, das nachweislich eine
 * LoxBerry-Wurzel IST: es traegt config/plugins, data/plugins UND
 * config/system/general.json (Regeln/06). Eine feste
 * Zahl ".." waere nur die naechste Wette (installiert liegt diese Datei drei
 * Ebenen unter der Wurzel, im entpackten Archiv zwei), und ein
 * ausgeschriebener Systempfad ist ein harter Pfad - den beanstandet der
 * Pluginpruefer beim Einspielen zu Recht.
 */
function bw_wurzel_suchen()
{
    $v = __DIR__;
    for ($i = 0; $i < 8 && $v !== '' && $v !== dirname($v); $i++) {
        /* general.json ist die entscheidende Bedingung: die beiden Ordner
           allein hinterlaesst jeder Pruefstand. Bis 0.9.19 genuegten sie -
           gemessen am 25.09.2026 in WSL (Pruefung-Beschattungswaechter-0.9.20,
           Fall W9): die Bibliothek, abgelegt wie installiert in einem fremden
           Baum ohne general.json, nahm diesen Baum als Wurzel. */
        if (is_dir($v . '/config/plugins') && is_dir($v . '/data/plugins')
            && is_file($v . '/config/system/general.json')) {
            return $v;
        }
        $v = dirname($v);
    }
    return '';
}

/**
 * Die Wurzel: erst LBHOMEDIR, dann die Suche - und danach nichts mehr.
 *
 * Ein gesetztes LBHOMEDIR gilt mit config/plugins UND data/plugins darunter;
 * general.json wird dort nicht verlangt, damit die Attrappen der Pruefkette
 * (Werkzeuge/lb) weiter tragen. Rueckgabe '' heisst "keine Wurzel". Bauart
 * tb_lbhome() aus Spotpreis-Tibber 0.9.19.
 */
function bw_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if (is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return bw_wurzel_suchen();
}

/**
 * Pfade des Plugins. LBP*-Umgebungsvariablen setzt LoxBerry.
 *
 * ARCHIVMODUS. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek dort
 * installiert liegt (<Wurzel>/webfrontend/html/plugins/<ordner>, physisch
 * verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt
 * ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit ihrer
 * Attrappe). Sonst ist das ein ausgepacktes Archiv oder ein Pruefordner, und
 * alles bleibt in dessen eigenem Ordner. Bis 0.9.19 nahm ein Archiv die
 * gefundene Wurzel bzw. $LBHOMEDIR (am Geraet steht es in /etc/environment)
 * und den festen Namen: bw_lauf.php --jetzt schickte den Befehl an den
 * Miniserver der Anlage und schrieb deren stand.json, der Endpunkt nahm deren
 * Merkwort an, die Oberflaeche schrieb deren Konfiguration (in WSL gemessen,
 * Pruefung-Beschattungswaechter-0.9.20, Faelle A1-A5). Bauart tb_paths() aus
 * Spotpreis-Tibber 0.9.19.
 *
 * OHNE WURZEL wird neben dem Plugin gearbeitet, nie an der Laufwerkswurzel:
 * bis 0.9.19 lauteten die Pfade dann /config/plugins/..., /data/plugins/...
 * und /log/plugins/... (Fall T6). bin/bw_lauf.php steigt in beiden Faellen
 * vorher aus (bw_keine_wurzel_abbruch()); dieser Zweig bedient Oberflaeche,
 * Healthcheck und Endpunkt auf einem Bau-Rechner.
 */
function bw_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    /* Von LBPPLUGINDIR zaehlt nur der letzte Pfadteil, und die Namen, die
       nachweislich kein Pluginordner sind, gelten auch dort nicht.
       Der Ordnername ist sonst die LETZTE Stufe des Pfades - sowohl
       webfrontend/html/plugins/<ordner>/ als auch bin/plugins/<ordner>/
       enden darauf. Eine Stufe zu weit oben ergaebe "plugins". */
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '/', 'html', 'htmlauth', 'plugins', 'bin'), true));
    $ordner = $lbp_gilt ? $lbp : basename(__DIR__);
    if ($ordner === '' || $ordner === '.' || $ordner === '/'
        || $ordner === 'html' || $ordner === 'htmlauth' || $ordner === 'plugins'
        || $ordner === 'bin') {
        $ordner = 'beschattungswaechter';
    }
    $lb = bw_lbhome();
    $gefunden = $lb;
    if ($lb !== '') {
        $soll = @realpath($lb . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $lb === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $lb = '';
        }
    }
    if ($lb === '') {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'plugin'  => $ordner,
            'lbhome'  => '',
            'config'  => $basis . '/config',
            'log'     => $basis . '/log',
            'datadir' => $basis . '/data',
            /* Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
               liegt (Archivmodus) - fuer die Meldung; sonst leer. */
            'archiv'  => $gefunden,
        );
        $p['cfgdatei'] = $p['config'] . '/beschattung.json';
        $p['sicherung'] = $p['config'] . '/beschattung.backup.json';
        $p['logdatei'] = $p['log'] . '/beschattung.log';
        $p['praefix_alt'] = $p['config'] . '/beschattung.mqtt_praefix_alt';
        return $p;
    }
    $cfg = getenv('LBPCONFIGDIR');
    $log = getenv('LBPLOGDIR');
    $dat = getenv('LBPDATADIR');
    $p = array(
        'plugin' => $ordner,
        'lbhome' => $lb,
        'config' => ($cfg !== false && $cfg !== '') ? $cfg : $lb . '/config/plugins/' . $ordner,
        'log'    => ($log !== false && $log !== '') ? $log : $lb . '/log/plugins/' . $ordner,
        'datadir'   => ($dat !== false && $dat !== '') ? $dat : $lb . '/data/plugins/' . $ordner,
        'archiv' => '',
    );
    $p['cfgdatei'] = $p['config'] . '/beschattung.json';
    /* Die Zweitschrift liegt NEBEN dem Konfigordner, nicht darin: der
       Installer entfernt config/plugins/<ordner>/ bei jedem Upgrade und bei
       der Deinstallation. Eine Sicherung im Ordner straebe also genau in dem
       Fall mit, fuer den es sie gibt. */
    $p['sicherung'] = $lb . '/config/plugins/' . $ordner . '.backup.json';
    $p['logdatei'] = $p['log'] . '/beschattung.log';
    /* Das zuletzt benutzte ALTE MQTT-Praefix (M2) - NEBEN dem Konfigordner
       wie die Zweitschrift: der Installer raeumt den Ordner bei jedem Upgrade
       ab, und die Deinstallation braucht den Namen noch. */
    $p['praefix_alt'] = $lb . '/config/plugins/' . $ordner . '.mqtt_praefix_alt';
    return $p;
}

/**
 * Fuer bin/bw_lauf.php: ohne Wurzel oder aus einem Archiv heraus nichts tun,
 * eine Meldung auf stderr, Rueckgabewert 1 - VOR allem, was sendet oder
 * schreibt. Bauart tb_keine_wurzel_abbruch() aus Spotpreis-Tibber 0.9.19.
 */
function bw_keine_wurzel_abbruch($programm)
{
    $p = bw_paths();
    if ($p['lbhome'] !== '') {
        return;
    }
    if ($p['archiv'] !== '') {
        fwrite(STDERR, $programm . ': Diese Datei liegt nicht in der Installation unter '
            . $p['archiv'] . "\n"
            . '(ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt,' . "\n"
            . 'wurde nichts gesendet und nichts geschrieben.' . "\n"
            . 'Abhilfe: das Programm aus ' . $p['archiv'] . '/bin/plugins/<ordner> aufrufen' . "\n"
            . 'oder LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.' . "\n");
        exit(1);
    }
    fwrite(STDERR, $programm . ': Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.' . "\n"
        . '$LBHOMEDIR ist nicht gesetzt, und oberhalb von ' . __DIR__ . ' traegt kein' . "\n"
        . 'Verzeichnis config/plugins, data/plugins und config/system/general.json.' . "\n"
        . 'Es wurde nichts gesendet und nichts geschrieben.' . "\n");
    exit(1);
}

/**
 * Vorgaben.
 *
 * 'uuid' ist ab Werk LEER, und das mit Absicht: eine Kennung gehoert zu
 * genau einem Baustein in genau einer Anlage. Eine mitgelieferte waere in
 * jeder fremden Installation falsch - und zwar unsichtbar falsch, denn der
 * Miniserver antwortet auf eine unbekannte Kennung, ohne dass etwas
 * geschieht.
 *
 * Die eigene Kennung steht in der Loxone-App unter dem Baustein oder in der
 * Projektdatei. Solange sie fehlt, sendet bw_senden() nicht.
 *
 * 'abstand' 60 Minuten ist eine Abwaegung, keine technische Grenze: der
 * Befehl holt auch eine von Hand abgeschaltete Automatik zurueck. Wer einen
 * Rollladen in der Sonne hochfaehrt, hat ihn sonst nach kurzer Zeit wieder
 * unten. Eine Stunde ist lang genug, dass das nicht stoert, und kurz genug,
 * dass eine Fassade, deren Sonne mittags kommt, nicht den Tag verpasst.
 *
 * 'ms_nr' ist seit 0.9.11 dabei und traegt den SCHLUESSEL des Miniservers
 * aus general.json, nicht seine Stellung in der Liste. Eine Stellung ist
 * keine Adresse: wer in LoxBerry einen Miniserver ergaenzt oder entfernt,
 * dessen Waechter spraeche danach still mit einem anderen Geraet. Der
 * Schluessel fehlt in jeder aelteren Konfiguration - dann gilt weiter die
 * bisherige Zaehlung aus 'ms', und beim ersten Speichern wird er genau so
 * festgeschrieben, wie sie ausfiel. Eine bestehende Anlage merkt davon
 * nichts.
 *
 * MEHRERE ZIELE, seit 0.9.11: 'uuid'/'befehl' sind Ziel 1 und behalten ihre
 * Namen - eine bestehende Anlage merkt von der Erweiterung nichts. Die
 * weiteren sind DURCHNUMMERIERTE EINZELSCHLUESSEL, kein verschachteltes Feld:
 * die Sicherungsdatei bleibt damit flach, und jeder Wert geht durch dieselbe
 * Positivliste. Ein Ziel zaehlt als eingerichtet, wenn seine Kennung nicht
 * leer ist.
 *
 * ALLES NEUE IST AB WERK AUS. Der Endpunkt hat kein Merkwort, MQTT ist
 * abgeschaltet, und die Wirkungsmessung ebenso - wer sie nicht benutzt, soll
 * die Themen nicht im Broker haben und keine Abfrage an seinen Miniserver.
 */
function bw_vorgaben()
{
    return array(
        'aktiv'        => 0,
        'ms'           => 0,
        'ms_nr'        => '',
        'uuid'         => '',
        'befehl'       => 'auto',
        'uuid2'        => '',
        'befehl2'      => 'autoshade/1',
        'uuid3'        => '',
        'befehl3'      => 'autoshade/1',
        'uuid4'        => '',
        'befehl4'      => 'autoshade/1',
        'uuid5'        => '',
        'befehl5'      => 'autoshade/1',
        'uuid6'        => '',
        'befehl6'      => 'autoshade/1',
        'von'          => '06:00',
        'bis'          => '21:00',
        'abstand'      => 60,
        'timeout'      => 5,
        'aktionstoken' => '',
        'mqtt_ein'     => 0,
        'mqtt_thema'   => 'beschattung',
        'pruefen_ein'  => 0,
        /* Wetter-1 (Verbesserungsbau 30.09.2026), AB WERK AUS: die
           Ecowitt-Weiche als Quelle fuer Sonne und Wind - siehe Abschnitt I.
           sonne_min 120 W/m2 ist die Schwelle der WMO fuer Sonnenschein
           (direkte Strahlung); die Station misst Globalstrahlung, es ist also
           eine Naeherung. wind_max 0 heisst: Wind nicht pruefen. */
        'wetter_ein'   => 0,
        'wetter_token' => '',
        'sonne_min'    => 120,
        'wind_max'     => 0,
        /* Sonne-1 (Verbesserungsbau 01.10.2026), AB WERK AUS: der
           Sonnenstand der Fensterbilanz (MQTT haus/sonne/) - siehe
           Abschnitt J. fassade/fassadeN ordnen Ziel 1..6 eine oder mehrere
           Fassaden (Ausrichtung in ganzen Grad, wie die Fensterbilanz sie
           nennt) zu; leer heisst: dieses Ziel wird gedrueckt wie bisher. */
        'sonne_ein'    => 0,
        'fassade'      => '',
        'fassade2'     => '',
        'fassade3'     => '',
        'fassade4'     => '',
        'fassade5'     => '',
        'fassade6'     => '',
    );
}

/**
 * Schluessel, die eine Sicherung aus einer frueheren Fassung noch nicht
 * kennt (Wetter-1, Verbesserungsbau 30.09.2026). Fehlen sie in der Datei,
 * gilt ihre Vorgabe (ab Werk aus), und die Meldung sagt es - jeder andere
 * fehlende Schluessel bleibt eine Beanstandung.
 */
function bw_sicherung_spaeter()
{
    /* Sonne-1 (01.10.2026): dazu der Haken und die Zuordnung der Fassaden. */
    return array('wetter_ein', 'wetter_token', 'sonne_min', 'wind_max',
                 'sonne_ein', 'fassade', 'fassade2', 'fassade3', 'fassade4', 'fassade5', 'fassade6');
}

/** Die Kennungen und Befehle aller eingerichteten Ziele, in ihrer Reihenfolge. */
function bw_ziele(array $c)
{
    $aus = array();
    for ($i = 1; $i <= 6; $i++) {
        $ku = $i === 1 ? 'uuid' : 'uuid' . $i;
        $kb = $i === 1 ? 'befehl' : 'befehl' . $i;
        $u = bw_kennung_sauber(isset($c[$ku]) ? $c[$ku] : '');
        $b = bw_kennung_sauber(isset($c[$kb]) ? $c[$kb] : '');
        if ($u !== '' && $b !== '') {
            $aus[] = array('nr' => $i, 'uuid' => $u, 'befehl' => $b);
        }
    }
    return $aus;
}

/**
 * Taugt der Wert ueberhaupt fuer diese Datei?
 *
 * Die allgemeine Wache: kein Feld, kein Objekt, nichts Ueberlanges, keine
 * Steuerzeichen. Sie steht VOR der Pruefung je Schluessel, damit die dort
 * nicht mit einem Feld rechnen muss.
 */
function bw_wert_taugt($w)
{
    if (is_array($w) || is_object($w) || is_bool($w) || is_null($w)) {
        return false;
    }
    $s = (string) $w;
    if (strlen($s) > 512) {
        return false;
    }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/**
 * Ist der Wert fuer DIESE Einstellung zulaessig?
 *
 * Es gibt genau EINE Positivliste, und sie wird von drei Stellen benutzt:
 * vom Formular, vom Zurueckspielen einer Sicherung und vom Lesen der
 * Konfigurationsdatei. Eine zweite Wahrheit ueber zulaessige Werte gibt es
 * nicht - sonst nimmt die eine Stelle an, was die andere abweist.
 *
 * Dass auch die DATEI geprueft wird, ist kein Zierat: sie kann von Hand
 * geschrieben, aus einer Sicherung zurueckgespielt oder aus einer aelteren
 * Fassung uebernommen sein. Am 28.08.2026 gemessen: eine Sicherung mit
 * von="99:99" wurde anstandslos uebernommen, und das Zeitfenster war danach
 * nie mehr offen - der Waechter sendete nie wieder etwas, ohne ein Wort.
 */
function bw_wert_pruefen($schluessel, $wert)
{
    if (!bw_wert_taugt($wert)) {
        return false;
    }
    $s = trim((string) $wert);
    /* Die durchnummerierten Ziele werden wie das erste geprueft - mit EINER
       Ausnahme: ihr Befehl darf leer sein. Bis 0.9.12 wies die Liste ihn ab,
       und damit liess sich ein einmal eingetragenes Ziel nicht mehr
       ausraeumen: wer Kennung und Befehl von Ziel 2 leerte, bekam bei JEDEM
       Speichern "Befehl (Ziel 2) wurde abgewiesen" und die Meldung "nur
       teilweise gespeichert" - fuer einen Vorgang, der genau richtig war.
       Ziel 1 bleibt streng: es ist das Ziel, das es immer gibt. */
    if (preg_match('/^uuid[2-6]$/', $schluessel)) { $schluessel = 'uuid'; }
    if (preg_match('/^befehl[2-6]$/', $schluessel)) { $schluessel = 'befehl_weiteres'; }
    if (preg_match('/^fassade[2-6]$/', $schluessel)) { $schluessel = 'fassade'; }
    switch ($schluessel) {
        case 'aktiv':
        case 'mqtt_ein':
        case 'pruefen_ein':
        case 'wetter_ein':
        case 'sonne_ein':
            return $s === '0' || $s === '1';
        case 'ms':
            return preg_match('/^[0-9]{1,3}$/', $s) === 1;
        case 'ms_nr':
            return $s === '' || preg_match('/^[0-9]{1,3}$/', $s) === 1;
        case 'aktionstoken':
            /* NUR EINE ZEICHENKETTE (C6, Durchgang 30.09.2026). Bis 0.9.21 ging
               eine JSON-Zahl aus einer Sicherung durch: (string) machte aus
               1234567890123456 eine gueltige Form, und in der Datei stand
               danach eine Zahl - fuer bw_hat_inhalt() kein Merkwort.
               Leer heisst "abgeschaltet" (in einer Sicherung: "keins
               gesichert", siehe bw_sicherung_lesen()). Sonst jede Form, die
               ohne Kodierung in eine Adresse passt (Regeln/05, "Das
               Positivmuster fuer ein Token wird so weit gefasst, wie Token
               wirklich aussehen"): gemessen am 30.09.2026 an 1023 Token der
               Pruefstaende unter Werkzeuge/lb*, 14 Formen aus Klein- und
               Grossbuchstaben, Ziffern und Bindestrich, 10 bis 50 Zeichen.
               Die bis 0.9.21 geltende Form (16 bis 64 Hexadezimalzeichen)
               nahm davon 188 an. */
            return is_string($wert)
                && ($s === '' || preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $s) === 1);
        case 'mqtt_thema':
            /* Das Gateway liest ZEILENWEISE, mit dem Leerzeichen als Trenner
               zwischen Thema und Wert. Ein Praefix mit Leerzeichen oder
               Zeilenumbruch erzeugte erfundene Themen - deshalb steht die
               Wache hier und nicht erst im Formular. */
            return $s !== '' && strlen($s) <= 60
                && preg_match('#^[A-Za-z0-9_/\-]+$#', $s) === 1;
        case 'uuid':
            /* Leer ist erlaubt - das ist der Auslieferungszustand. */
            return $s === '' || bw_kennung_sauber($s) !== '';
        case 'befehl':
            return bw_kennung_sauber($s) !== '';
        case 'befehl_weiteres':
            /* Leer heisst "dieses Ziel gibt es nicht" - bw_ziele() zaehlt ein
               Ziel ohnehin nur, wenn Kennung UND Befehl dastehen. */
            return $s === '' || bw_kennung_sauber($s) !== '';
        case 'von':
        case 'bis':
            return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $s) === 1;
        case 'abstand':
            return preg_match('/^[0-9]{1,4}$/', $s) === 1 && (int) $s >= 5 && (int) $s <= 720;
        case 'timeout':
            return preg_match('/^[0-9]{1,2}$/', $s) === 1 && (int) $s >= 2 && (int) $s <= 30;
        case 'wetter_token':
            /* Das Wortzeichen der Ecowitt-Weiche - dieselbe Form, die die Weiche
               selbst zulaesst (ew_token_taugt(): [A-Za-z0-9_.-]{0,64}). Leer
               heisst: die Weiche hat keines gesetzt. Nur eine Zeichenkette,
               wie beim eigenen Merkwort (C6). */
            return is_string($wert)
                && ($s === '' || preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $s) === 1);
        case 'sonne_min':
            /* W/m2, 0 = Sonne nicht pruefen. 1500 liegt ueber jeder
               Globalstrahlung am Boden (Solarkonstante 1361 W/m2). */
            return preg_match('/^[0-9]{1,4}$/', $s) === 1 && (int) $s <= 1500;
        case 'wind_max':
            /* m/s, 0 = Wind nicht pruefen. */
            return preg_match('/^[0-9]{1,2}$/', $s) === 1 && (int) $s <= 60;
        case 'fassade':
            /* Sonne-1: leer = keine Zuordnung; sonst bis acht Ausrichtungen in
               ganzen Grad 0-359, durch Komma getrennt, ohne Leerzeichen und
               ohne fuehrende Null - genau die Schreibweise der Themen der
               Fensterbilanz (haus/sonne/fassade/<az>/wirkt). "90, 180" oder
               "090" wird beanstandet, nicht still berichtigt (Entscheidung 19). */
            if (!is_string($wert) && !is_int($wert)) {
                return false;
            }
            return $s === '' || preg_match('/^(0|[1-9][0-9]?|[12][0-9]{2}|3[0-5][0-9])'
                . '(,(0|[1-9][0-9]?|[12][0-9]{2}|3[0-5][0-9])){0,7}\z/', $s) === 1;
    }
    return false;
}

/** Einen Wert fuer eine Meldung kurz und ungefaehrlich machen. */
function bw_kurz($w)
{
    /* Uebersetzt (O13, Durchgang 30.09.2026): bw_kurz() geht nur in
       Meldungen an den Bediener - Formular und Zurueckspielen -, nie ins
       Protokoll. Bis 0.9.21 stand hier ein deutscher Satz in Umschrift, auch
       in der englischen Oberflaeche. */
    if (is_array($w)) {
        return sprintf(bw_t('TEXT.KURZ_LISTE'), count($w));
    }
    if (is_object($w)) {
        return bw_t('TEXT.KURZ_OBJEKT');
    }
    if (is_bool($w)) {
        return $w ? 'true' : 'false';
    }
    if (is_null($w)) {
        return 'null';
    }
    $s = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $w);
    return strlen($s) > 60 ? substr($s, 0, 60) . '...' : $s;
}

/**
 * Die Lage der Konfiguration - gemerkt beim letzten bw_config().
 *
 * Der Reiter Test zeigt sie an. Jeder Zustand, den der Code erzeugen kann,
 * braucht seinen Satz: 'neu', 'leer', 'ok', 'kaputt', 'aus_zweitschrift'.
 */
function bw_config_lage($setzen = null, $erste = false)
{
    static $l = array('lage' => 'unbekannt', 'fehlend' => array(), 'verworfen' => array(),
                      'war_kaputt' => false);
    /* DIE ERSTE LAGE DIESES PROZESSES wird gehalten (O4, Durchgang
       30.09.2026). Die Oberflaeche ruft bw_config() mehrmals; der erste
       Aufruf heilt eine beschaedigte Datei aus der Zweitschrift und schreibt
       sie zurueck, jeder spaetere sieht "ok". Bis 0.9.21 meldete die Zeile im
       Reiter Test deshalb "in Ordnung", waehrend die .kaputt-Datei daneben
       lag (Regeln/05, Robonect 1.1.0: "merkt sich den Zustand, bevor die
       Selbstheilung ihn beseitigt"). Ein spaeteres "ok" ueberschreibt die
       erste Lage nicht; bw_config_lage(null, true) liefert sie. */
    static $e = null;
    if ($setzen !== null) {
        $l = $setzen;
        if ($e === null) {
            $e = $setzen;
        }
    }
    if ($erste && $e !== null) {
        return $e;
    }
    return $l;
}

/**
 * Traegt ein Stand INHALT? Eine Kennung (uuid) oder ein Merkwort
 * (aktionstoken) in der Form, die die Positivliste zulaesst.
 *
 * Danach entscheiden die Selbstheilung, das Nachziehen der Zweitschrift und
 * die Hakenskripte (bw_hat_inhalt() in preupgrade.sh und postinstall.sh
 * prueft dasselbe) - nie nach Groesse oder "nicht leer" (Hausregel,
 * Bestand-2026-09-18/AUFTRAG_gemeinsam.md, "Selbstheilung nach Inhalt").
 */
function bw_hat_inhalt($d)
{
    if (!is_array($d)) {
        return false;
    }
    $u = (isset($d['uuid']) && is_scalar($d['uuid'])) ? bw_kennung_sauber((string) $d['uuid']) : '';
    $t = (isset($d['aktionstoken']) && is_string($d['aktionstoken'])) ? trim($d['aktionstoken']) : '';
    /* Seit 0.9.22 dieselbe Form wie bw_wert_pruefen('aktionstoken') (C6). */
    return $u !== '' || preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $t) === 1;
}

/** Die Zweitschrift - nur, wenn sie lesbar ist UND Inhalt traegt, sonst null. */
function bw_zweitschrift_mit_inhalt()
{
    $p = bw_paths();
    if (!is_file($p['sicherung'])) {
        return null;
    }
    $zs = json_decode((string) @file_get_contents($p['sicherung']), true);
    return bw_hat_inhalt($zs) ? $zs : null;
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false liest NUR. Der Schalter ist da, damit ein kuenftiger
 * unangemeldeter Endpunkt nichts anlegt und nichts heilt: wer sich nicht
 * ausweisen kann, hinterlaesst keine Datei - auch keine harmlose.
 *
 * EINE BESCHAEDIGTE DATEI IST EIN FEHLER, KEIN LEERER ZUSTAND. Bis 0.9.10
 * gab diese Funktion bei unlesbarem JSON stillschweigend die Werkseinstellung
 * zurueck. Der naechste Klick auf Speichern schrieb sie, und die Zweitschrift
 * daneben wurde mitkopiert - die einzige heile Kopie war damit fort.
 * Gemessen am 28.08.2026 an einer abgeschnittenen Datei: uuid danach leer, in
 * der Zweitschrift ebenfalls, kein Wort im Protokoll.
 *
 * Richtig ist: beiseitelegen (nicht ueberschreiben - darin koennen
 * Einstellungen stehen, die die Zweitschrift noch nicht kennt), genau eine
 * Protokollzeile, die Zweitschrift LESEN und daraus wiederherstellen.
 */
function bw_config($erzeugen = true)
{
    $p = bw_paths();
    $c = bw_vorgaben();
    $lage = 'neu';
    $fehlend = array();
    $verworfen = array();
    $d = null;
    $war_kaputt = false;

    if (is_file($p['cfgdatei'])) {
        $roh = (string) @file_get_contents($p['cfgdatei']);
        $d = json_decode($roh, true);
        if (!is_array($d)) {
            $lage = 'kaputt';
            $d = null;
            $war_kaputt = true;
            if ($erzeugen) {
                @rename($p['cfgdatei'], $p['cfgdatei'] . '.kaputt');
                bw_log_wenn_neu('kaputt',
                    'Die Konfiguration war unlesbar und liegt jetzt als beschattung.json.kaputt daneben.');
            }
            /* Geheilt wird nur aus einer Zweitschrift, die selbst Inhalt
               traegt. Bis 0.9.19 genuegte jedes nicht leere Feld - eine
               Zweitschrift {"aktiv":1} wurde zur Konfiguration (Fall Z3). */
            $zs = bw_zweitschrift_mit_inhalt();
            if ($zs !== null) {
                $d = $zs;
                $lage = 'aus_zweitschrift';
                if ($erzeugen) {
                    /* LESEN und ZURUECKSCHREIBEN. Nur zu lesen hiesse: die
                       Datei fehlt weiter, jeder Aufruf zieht die Sicherung
                       erneut, und jeder Aufruf schreibt eine Protokollzeile. */
                    bw_json_schreiben($p['cfgdatei'], $zs);
                    bw_log_wenn_neu('geheilt',
                        'Konfiguration aus der Zweitschrift wiederhergestellt.');
                }
            }
        } elseif (!$d) {
            /* Datei da, Inhalt {} - das ist der Aktualisierungsfall, den eine
               Neuinstallation nie durchlaeuft. */
            $lage = 'leer';
        } else {
            $lage = 'ok';
        }
    }

    /* DIE DATEI FEHLT ODER IST LEER, UND DIE ZWEITSCHRIFT TRAEGT INHALT:
     * zurueckschreiben, einmal melden.
     *
     * Bis 0.9.19 heilte nur eine beschaedigte Datei. Fehlte sie, galt die
     * Werkseinstellung - und genau das ist der Zustand in der Luecke eines
     * Updates: purge_installation hat config/plugins/<ordner>/ geloescht,
     * postinstall.sh laeuft erst spaeter (Regeln/06), und der Fuenfminutentakt
     * fand "kein Ziel eingerichtet" und meldete eine Stoerung an das
     * Benachrichtigungszentrum (in WSL gemessen,
     * Pruefung-Beschattungswaechter-0.9.20, Fall Z13). Die Zweitschrift ist
     * in dem Augenblick die Sicherung, die preupgrade.sh gerade geschrieben
     * hat. $erzeugen = false liest sie nur. */
    if ($lage === 'neu' || $lage === 'leer') {
        $zs = bw_zweitschrift_mit_inhalt();
        if ($zs !== null) {
            $d = $zs;
            $lage = 'aus_zweitschrift';
            if ($erzeugen && bw_json_schreiben($p['cfgdatei'], $zs)) {
                bw_log_wenn_neu('geheilt',
                    'Konfiguration aus der Zweitschrift wiederhergestellt (die Datei fehlte oder war leer).');
            }
        }
    }

    if (is_array($d)) {
        foreach ($c as $k => $v) {
            if (!array_key_exists($k, $d)) {
                $fehlend[] = $k;
                continue;
            }
            if (bw_wert_pruefen($k, $d[$k])) {
                $c[$k] = is_string($d[$k]) ? trim($d[$k]) : $d[$k];
            } else {
                $verworfen[] = $k;
            }
        }
    }

    /* Der Schluessel ms_nr fehlt in jeder Konfiguration vor 0.9.11. Dann
       gilt die bisherige Zaehlung weiter - siehe bw_vorgaben(). */
    $c['abstand'] = max(5, min(720, (int) $c['abstand']));
    $c['timeout'] = max(2, min(30, (int) $c['timeout']));

    /* DIE KONFIGURATION WIRD VERVOLLSTAENDIGT, NICHT NUR ERGAENZT: fehlt ein
       Schluessel, wird er EINMAL mit seiner Vorgabe geschrieben. Danach heisst
       "fehlt" nie mehr "gilt als 1", sondern es steht da - und eine kuenftige
       Umbenennung wird harmlos, weil man in der Datei sieht, was gesetzt ist.
       Nicht bei verworfenen Werten: dort wuerde die Vorgabe einen falschen
       Wert still ueberschreiben, statt ihn zu melden.

       DAS MERKWORT WIRD DABEI AUSGENOMMEN. Es unterscheidet zwei Zustaende,
       die fuer empty() gleich aussehen: "Schluessel fehlt" heisst noch nie
       gesetzt, "Schluessel da, leer" heisst bewusst abgeschaltet. Wuerde die
       Vervollstaendigung ein leeres Merkwort schreiben, gaebe es den ersten
       Zustand nie wieder - und ein selbst erzeugtes Merkwort koennte nie
       entstehen. */
    $bw_zu_ergaenzen = array();
    foreach ($fehlend as $bw_k) {
        if ($bw_k !== 'aktionstoken') { $bw_zu_ergaenzen[] = $bw_k; }
    }
    if ($erzeugen && $bw_zu_ergaenzen && !$verworfen
            && $lage !== 'kaputt' && $lage !== 'neu') {
        $bw_schreib = $c;
        if (in_array('aktionstoken', $fehlend, true)) {
            unset($bw_schreib['aktionstoken']);
        }
        if (bw_json_schreiben($p['cfgdatei'], $bw_schreib)) {
            bw_log_wenn_neu('vervollstaendigt',
                'Konfiguration vervollstaendigt, ergaenzt wurde: '
                . implode(', ', $bw_zu_ergaenzen));
            $fehlend = in_array('aktionstoken', $fehlend, true)
                ? array('aktionstoken') : array();
        }
    }

    /* EIN VERWORFENER WERT GEHOERT INS PROTOKOLL, NICHT NUR IN DIE LAGE.
     *
     * bw_config_lage() liest bis heute nur der Reiter Test. Cron, Healthcheck
     * und bw_befund() sehen es nie - eine Datei mit von="99:99" galt damit
     * still als 06:00, und im kopflosen Betrieb sagte das niemandem etwas.
     * Ueberschrieben wird der Wert weiterhin nicht: gemeldet ist gemeldet,
     * und die Vorgabe daraufzuschreiben verdeckte den Fehler erst recht. */
    if ($verworfen) {
        bw_log_wenn_neu('verworfen',
            'In der Konfiguration stehen unzulaessige Werte; es gelten dafuer die '
            . 'Vorgaben: ' . implode(', ', $verworfen));
    }

    bw_config_lage(array('lage' => $lage, 'fehlend' => $fehlend, 'verworfen' => $verworfen,
                         'war_kaputt' => $war_kaputt));
    return $c;
}

/**
 * JSON schreiben - unteilbar, mit den Rechten VOR dem Inhalt.
 *
 * Drei Dinge, die einzeln schon Schaden angerichtet haben:
 *
 *  1. Der Rueckgabewert von json_encode() wird ANGESEHEN. Gibt es false
 *     zurueck, machte file_put_contents() daraus eine leere Zeichenkette,
 *     schrieb null Byte und meldete Erfolg - der Rueckgabewert ist dann 0
 *     und nicht false. Gemessen am 28.08.2026: Datei danach 0 Byte, und die
 *     Funktion meldete true.
 *  2. Die Rechte stehen vor dem Inhalt. "Schreiben, dann chmod" laesst die
 *     Datei fuer die Dauer des Schreibens mit den Rechten der umask stehen.
 *  3. Die Nebendatei traegt die PID. Oberflaeche und Cron schreiben dieselbe
 *     Datei; ohne die PID ueberschreibt einer die Nebendatei des anderen,
 *     und umbenannt wird eine Mischung.
 *
 * Verglichen wird mit !== strlen($js), nicht mit === false: eine kurze
 * Schreibung ist genauso kaputt wie gar keine, meldet sich aber nicht.
 */
function bw_json_schreiben($pfad, $daten, $rechte = 0600)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($js) || $js === '') {
        return false;
    }
    return bw_text_schreiben($pfad, $js, $rechte);
}

/**
 * Text schreiben - unteilbar, mit den Rechten VOR dem Inhalt.
 *
 * Der Weg, den bw_json_schreiben() bis 0.9.21 selbst ging, unveraendert
 * herausgezogen, damit ihn auch das Formularmerkwort (C8), die Einmalmeldung
 * der Oberflaeche (O1) und die Abodatei des Gateways (M4) gehen. Die Punkte 2
 * und 3 der Beschreibung ueber bw_json_schreiben() gelten hier.
 */
function bw_text_schreiben($pfad, $inhalt, $rechte = 0600)
{
    $js = (string) $inhalt;
    $verz = dirname($pfad);
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, $rechte);
    if (!@ftruncate($fh, 0)) {
        fclose($fh);
        @unlink($tmp);
        return false;
    }
    $n = @fwrite($fh, $js);
    @fflush($fh);
    fclose($fh);
    if ($n !== strlen($js)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    @chmod($pfad, $rechte);
    return true;
}

/**
 * Die Konfiguration speichern - und die Zweitschrift daneben nachziehen.
 *
 * DIE ZWEITSCHRIFT DARF NIE SCHLECHTER WERDEN ALS DAS, WAS SIE SICHERT.
 * Wer eine Werkseinstellung ueber eine eingerichtete kopiert, hat genau den
 * Verlust angerichtet, gegen den es sie gibt. Geprueft wird an der Kennung:
 * traegt der zu schreibende Stand keine und die vorhandene Zweitschrift
 * eine, bleibt sie stehen - und das Protokoll sagt es.
 */
function bw_config_speichern(array $c, $zweitschrift = true)
{
    $p = bw_paths();
    if (!bw_json_schreiben($p['cfgdatei'], $c)) {
        bw_log('FEHLER: die Konfiguration liess sich nicht schreiben.');
        return false;
    }
    if (!$zweitschrift) {
        return true;
    }
    $alt = is_file($p['sicherung'])
        ? json_decode((string) @file_get_contents($p['sicherung']), true) : null;
    /* Auch nach INHALT, nicht nur nach der Kennung: ein Stand ohne Kennung UND
       ohne Merkwort ueberschreibt keine Zweitschrift, die eines davon traegt.
       Bis 0.9.19 ging dabei das Merkwort einer Anlage ohne Kennung verloren
       (Fall Z4). */
    if (!bw_hat_inhalt($c) && bw_hat_inhalt($alt)) {
        bw_log_wenn_neu('zweitschrift',
            'Die Zweitschrift wurde NICHT ueberschrieben: der zu schreibende Stand traegt '
            . 'weder Kennung noch Merkwort, die vorhandene schon.');
        return true;
    }
    $altkennung = (is_array($alt) && isset($alt['uuid'])) ? trim((string) $alt['uuid']) : '';
    $neukennung = isset($c['uuid']) ? trim((string) $c['uuid']) : '';
    if ($neukennung === '' && $altkennung !== '') {
        bw_log_wenn_neu('zweitschrift',
            'Die Zweitschrift wurde NICHT ueberschrieben: der zu schreibende Stand traegt '
            . 'keine Kennung, die vorhandene schon.');
        return true;
    }
    if (!bw_json_schreiben($p['sicherung'], $c)) {
        bw_log('FEHLER: die Zweitschrift liess sich nicht schreiben.');
    }
    return true;
}

function bw_log($text)
{
    $p = bw_paths();
    if (!is_dir($p['log'])) {
        @mkdir($p['log'], 0775, true);
    }
    $f = $p['logdatei'];
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        $z = file($f, FILE_IGNORE_NEW_LINES) ?: array();
        @file_put_contents($f, implode("\n", array_slice($z, -800)) . "\n");
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $text . "\n", FILE_APPEND);
}

/**
 * Dieselbe Meldung hoechstens einmal je Stunde.
 *
 * Ohne Bremse schreibt ein Fuenfminutentakt dieselbe Zeile 288 mal am Tag,
 * und die eine wichtige geht darin unter. Der Merker gehoert zurueckgesetzt,
 * sobald das Protokoll fort ist (gekappt, geleert, oder nach einem Neustart
 * der Ramdisk verschwunden) - sonst unterdrueckt die Bremse ausgerechnet die
 * ERSTE Zeile in einer leeren Datei.
 */
function bw_log_wenn_neu($merker, $text, $sekunden = 3600)
{
    $p = bw_paths();
    $f = $p['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/', '', strtolower($merker));
    if (!is_file($p['logdatei']) && is_file($f)) {
        @unlink($f);
    }
    if (is_file($f) && (time() - (int) @filemtime($f)) < $sekunden) {
        return false;
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    @touch($f);
    bw_log($text);
    return true;
}

/**
 * Eine Sperre gegen zwei gleichzeitige Laeufe.
 *
 * Nicht blockierend: wer nicht drankommt, geht wieder - der naechste Takt
 * kommt ohnehin gleich. Die Sperre gehoert dorthin, wo ein Abruf auf eine
 * Gegenstelle wartet, und das tut dieser Lauf.
 *
 * Rueckgabe: die offene Datei (der Aufrufer muss sie halten, sonst faellt die
 * Sperre) oder false.
 */
function bw_sperre()
{
    $p = bw_paths();
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $fh = @fopen($p['datadir'] . '/lauf.lock', 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

/**
 * Die Miniserver aus der zentralen LoxBerry-Konfiguration.
 *
 * Damit steht das Passwort NICHT in diesem Plugin und auch nicht in der
 * Loxone-Projektdatei - es bleibt dort, wo LoxBerry es ohnehin fuehrt.
 *
 * 'nr' ist der SCHLUESSEL aus general.json und damit die Adresse; die
 * Stellung im zurueckgegebenen Feld ist nur eine Reihenfolge.
 */
function bw_miniserver()
{
    $p = bw_paths();
    $f = $p['lbhome'] . '/config/system/general.json';
    if ($p['lbhome'] === '' || !is_file($f)) {
        return array();
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || empty($j['Miniserver'])) {
        return array();
    }
    $aus = array();
    foreach ($j['Miniserver'] as $nr => $ms) {
        if (!is_array($ms)) {
            continue;
        }
        $adresse = '';
        foreach (array('Ipaddress', 'IPAddress') as $k) {
            if (!empty($ms[$k])) { $adresse = $ms[$k]; break; }
        }
        if ($adresse === '') {
            continue;
        }
        $aus[] = array(
            'nr'      => (string) $nr,
            'name'    => !empty($ms['Name']) ? $ms['Name'] : ('Miniserver ' . $nr),
            'adresse' => $adresse,
            'port'    => !empty($ms['Port']) ? (int) $ms['Port'] : 80,
            'user'    => !empty($ms['Admin']) ? $ms['Admin'] : (!empty($ms['Username']) ? $ms['Username'] : ''),
            'pass'    => !empty($ms['Pass']) ? $ms['Pass'] : (!empty($ms['Password']) ? $ms['Password'] : ''),
        );
    }
    return $aus;
}

/**
 * Den eingestellten Miniserver auswaehlen.
 *
 * Vorrang hat der Schluessel; die Stellung ist die Rueckfallebene fuer jede
 * Konfiguration vor 0.9.11.
 */
/* Das Fragezeichen gehoert an den TYP, nicht bloss an die Vorgabe: ein
   implizit nullbarer Parameter ist seit PHP 8.4 ueberholt, und php -l meldet
   das in derselben Ausgabe wie "No syntax errors detected". Die
   Fragezeichen-Form gibt es seit 7.1 und traegt damit in jeder Fassung, die
   LoxBerry faehrt. */
function bw_miniserver_gewaehlt(array $c, ?array $alle = null)
{
    if ($alle === null) {
        $alle = bw_miniserver();
    }
    if (!$alle) {
        return null;
    }
    $nr = isset($c['ms_nr']) ? trim((string) $c['ms_nr']) : '';
    if ($nr !== '') {
        foreach ($alle as $m) {
            if ($m['nr'] === $nr) {
                return $m;
            }
        }
        /* GESETZT UND UNAUFFINDBAR IST EIN FEHLER, KEINE RUECKFALLEBENE.
         *
         * Bis 0.9.12 fiel dieser Fall auf die Stellung zurueck - und stellte
         * damit genau den Unfall wieder her, gegen den ms_nr in 0.9.11
         * eingefuehrt wurde: wer in LoxBerry einen Miniserver entfernt,
         * dessen Waechter spraeche danach still mit einem anderen Geraet.
         * Die Rueckfallebene gilt weiter fuer den Fall, fuer den es sie gibt:
         * eine Konfiguration VOR 0.9.11, in der ms_nr leer ist. */
        bw_log_wenn_neu('ms_nr',
            'Der eingestellte Miniserver (Schluessel ' . $nr . ') steht nicht mehr in der '
            . 'LoxBerry-Konfiguration. Es wird NICHT auf ein anderes Geraet ausgewichen - '
            . 'bitte im Reiter Einstellungen neu waehlen.');
        return null;
    }
    $i = isset($c['ms']) ? (int) $c['ms'] : 0;
    return isset($alle[$i]) ? $alle[$i] : $alle[0];
}

/** Kennung pruefen: Loxone-UUID oder ein Bausteinname ohne Sonderzeichen. */
function bw_kennung_sauber($s)
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-\/]{1,80}$/', $s)) {
        return '';
    }
    return $s;
}

/**
 * Den Befehl an den Miniserver schicken.
 *
 * DIE ZEITSCHRANKE DECKT AUCH DEN VERBINDUNGSAUFBAU. Die Angabe 'timeout' im
 * Stream-Kontext gilt nur fuer das LESEN; fuer den Aufbau gilt sonst
 * default_socket_timeout - auf einem LoxBerry 60 Sekunden. Gemessen an der
 * Ecowitt-Weiche am 23.08.2026 auf dem Geraet: 8134 ms bei timeout => 4.
 * Ein Ausweichen, das langsamer ist als der Ausfall, hilft niemandem, und
 * ein Fuenfminutentakt vertraegt keine Minute Wartezeit.
 *
 * Mit curl ist die Frage erledigt (CONNECTTIMEOUT neben TIMEOUT); ohne curl
 * wird default_socket_timeout fuer die Dauer des Aufrufs gesetzt und danach
 * zurueckgestellt.
 *
 * Beide Wege verhalten sich gleich: keiner folgt einer Weiterleitung. Wer
 * ihr folgt, schickt die Kopfzeile Authorization erneut mit - an ein Ziel,
 * das die Gegenstelle bestimmt.
 *
 * Rueckgabe: array(ok, code, text, url)
 */
function bw_senden(array $c, $uuid = null, $befehl = null)
{
    /* ZWEI TEXTE JE GRUND (O13, Durchgang 30.09.2026): 'text' bleibt der
       deutsche Satz fuer das Protokoll, das einsprachig ist (Regeln/03);
       'meldung' ist derselbe Grund fuer die Oberflaeche, uebersetzt. Bis
       0.9.21 stand der deutsche Satz auch in der englischen Oberflaeche. */
    $alle = bw_miniserver();
    if (!$alle) {
        return array('ok' => false, 'code' => 0,
                     'text' => 'kein Miniserver in der LoxBerry-Konfiguration',
                     'meldung' => bw_t('TEXT.SENDEN_KEIN_MS'), 'url' => '');
    }
    $m = bw_miniserver_gewaehlt($c, $alle);
    /* Seit 0.9.13 kann die Auswahl null liefern: ein eingestellter, aber
       nicht mehr vorhandener Schluessel weicht NICHT auf ein anderes Geraet
       aus. Wer eine Bedingung einzieht, faengt den Fall ab, den sie neu
       erzeugt - sonst stuende hier ein Zugriff auf null. */
    if ($m === null) {
        return array('ok' => false, 'code' => 0,
                     'text' => 'der eingestellte Miniserver steht nicht mehr in der '
                             . 'LoxBerry-Konfiguration',
                     'meldung' => bw_t('TEXT.SENDEN_MS_FEHLT'), 'url' => '');
    }
    $uuid = bw_kennung_sauber($uuid === null ? (isset($c['uuid']) ? $c['uuid'] : '') : $uuid);
    $befehl = bw_kennung_sauber($befehl === null ? (isset($c['befehl']) ? $c['befehl'] : '') : $befehl);
    if ($uuid === '' || $befehl === '') {
        return array('ok' => false, 'code' => 0,
                     'text' => 'Kennung oder Befehl unbrauchbar',
                     'meldung' => bw_t('TEXT.SENDEN_KENNUNG'), 'url' => '');
    }
    /* Der Trockenlauf geht durch DENSELBEN Weg - alle Wachen greifen echt,
       nur das Senden unterbleibt. Eine zweite Funktion, die den Vorgang
       beschreibt, liefe mit dem Sendecode auseinander, und dann zeigte die
       Vorschau etwas anderes an, als der Ernstfall tut. */
    if (bw_trocken()) {
        return array('ok' => true, 'code' => 0, 'probe' => true,
                     'text' => 'PROBE - es wurde NICHTS gesendet',
                     'meldung' => bw_t('TEXT.SENDEN_PROBE'),
                     'url' => 'http://' . $m['adresse'] . ':' . $m['port'] . '/dev/sps/io/'
                            . rawurlencode($uuid) . '/'
                            . implode('/', array_map('rawurlencode', explode('/', $befehl))));
    }
    /* Der Befehl kann einen Schraegstrich tragen (autoshade/1). Er ist ein
       Pfadtrenner und wird deshalb NICHT mitkodiert - die Kennung schon. */
    $pfad = rawurlencode($uuid) . '/' . implode('/', array_map('rawurlencode', explode('/', $befehl)));
    $url = 'http://' . $m['adresse'] . ':' . $m['port'] . '/dev/sps/io/' . $pfad;
    $frist = max(2, min(30, (int) $c['timeout']));
    $kopf = array('Accept: */*');
    if ($m['user'] !== '') {
        $kopf[] = 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']);
    }

    $antwort = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $kopf,
            CURLOPT_CONNECTTIMEOUT => $frist,
            CURLOPT_TIMEOUT        => $frist,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'LoxBerry Beschattungswaechter',
        ));
        $antwort = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        if ($antwort === false && $code === 0) {
            return array('ok' => false, 'code' => 0,
                         'text' => $fehler !== '' ? $fehler : 'keine Antwort', 'url' => $url);
        }
    } else {
        /* Der Weg ohne curl - seit 0.9.22 ueber bw_http_ohne_curl() (C4). */
        list($code, $antwort) = bw_http_ohne_curl($url, $kopf, $frist);
    }

    /* Die Adresse OHNE Zugangsdaten zurueckgeben - sie landet im Protokoll
       und in der Oberflaeche. */
    return array(
        'ok'   => ($code >= 200 && $code < 300),
        'code' => $code,
        'text' => $antwort === false ? 'keine Antwort' : trim((string) $antwort),
        'url'  => $url,
    );
}

function bw_stand_lesen()
{
    $p = bw_paths();
    $f = $p['datadir'] . '/stand.json';
    if (!is_file($f)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

function bw_stand_schreiben(array $s)
{
    $p = bw_paths();
    /* Der Zwischenstand ist neu erzeugbar und traegt kein Geheimnis - 0644
       genuegt, und der Cron laeuft ohnehin als loxberry. */
    return bw_json_schreiben($p['datadir'] . '/stand.json', $s, 0644);
}

/** Liegt die Uhrzeit im eingestellten Fenster? */
function bw_im_fenster(array $c, $zeit = null)
{
    $zeit = $zeit === null ? time() : $zeit;
    $jetzt = (int) date('H', $zeit) * 60 + (int) date('i', $zeit);
    $z = function ($s) {
        $t = explode(':', (string) $s);
        return (int) $t[0] * 60 + (isset($t[1]) ? (int) $t[1] : 0);
    };
    $von = $z($c['von']);
    $bis = $z($c['bis']);
    if ($von <= $bis) {
        return $jetzt >= $von && $jetzt <= $bis;
    }
    /* Fenster ueber Mitternacht */
    return $jetzt >= $von || $jetzt <= $bis;
}

/** Die Fassung aus der Plugin-Datenbank - ueber den ORDNERNAMEN gesucht. */
/**
 * Die Fassung - erst aus der Datenbank, dann aus der plugin.cfg.
 *
 * NEU 0.9.16. bw_fassung_db() ist die bisherige Funktion, unveraendert; sie
 * ist auf einer Installation der richtige und einzige Weg (die plugin.cfg
 * wird dort nirgendwohin installiert). Im AUSPACKORDNER gibt es aber keine
 * Datenbank, und dort gab sie deshalb eine leere Zeichenkette zurueck - am
 * Pruefstand gemessen am 07.09.2026. Betroffen war jeder Lauf im
 * Arbeitsordner.
 */
function bw_fassung()
{
    $v = bw_fassung_db();
    if ($v !== '') { return $v; }
    /* Nur die plugin.cfg des eigenen Archivs. Der zweite Kandidat eine Stufe
       hoeher lag installiert unter webfrontend/ und fand nie etwas; aus einem
       Archiv unter / war er die plugin.cfg der Laufwerkswurzel (Fall T11). */
    foreach (array(dirname(dirname(__DIR__)) . '/plugin.cfg') as $k) {
        if (!is_readable($k)) { continue; }
        $roh = (string) @file_get_contents($k);
        if (preg_match('/^\s*VERSION\s*=\s*([^\r\n]+)/mi', $roh, $m)) {
            return trim($m[1], " \t\"'");
        }
    }
    return '';
}

/** Die Fassung aus der Plugin-Datenbank des LoxBerry - der Weg am Geraet. */
function bw_fassung_db()
{
    $p = bw_paths();
    $f = $p['lbhome'] . '/data/system/plugindatabase.json';
    if ($p['lbhome'] === '' || !is_file($f)) {
        return '';
    }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || empty($d['plugins'])) {
        return '';
    }
    foreach ($d['plugins'] as $e) {
        /* Nie ueber die Kennung suchen: LoxBerry bildet sie aus Autorenname,
           E-Mail und Plugin-Name und sie aendert sich bei einem Fork. */
        if (is_array($e) && isset($e['folder']) && $e['folder'] === $p['plugin']) {
            return isset($e['version']) ? (string) $e['version'] : '';
        }
    }
    return '';
}

/**
 * Die Sicherungsdatei bauen.
 *
 * VOLLSTAENDIG, aus den Vorgaben heraus: geschrieben werden ALLE Schluessel,
 * nicht nur die abweichenden. Ein Schluessel, der in der Sicherung fehlt,
 * kaeme beim Zurueckspielen aus der Vorgabe - und das ist genau dann falsch,
 * wenn der Anwender ihn bewusst auf den Vorgabewert gesetzt hatte und sich
 * die Vorgabe spaeter aendert.
 *
 * DER LESBARE KOPF steht davor. Wer die Datei in einem Jahr findet, muss
 * erkennen koennen, was sie ist. Die Schluessel beginnen mit einem
 * Unterstrich, und bw_sicherung_lesen() UEBERGEHT sie - sonst lehnte diese
 * Linie genau die Datei ab, die sie zwei Zeilen vorher erzeugt hat.
 *
 * ZUM AKTIONSTOKEN: die Datei traegt es, und sie soll es tragen. Wer eine
 * Sicherung zurueckspielt, will den Waechter wieder so vorfinden, wie er war -
 * und dazu gehoert das Merkwort, denn ohne dasselbe Merkwort zeigen alle
 * Adressen in der Loxone-Projektdatei ins Leere.
 *
 * DAMIT IST DIE DATEI EIN GEHEIMNIS. Der Kopf sagt es, der Hinweis am Knopf
 * sagt es, und beide sagen dasselbe. Bis 0.9.12 stand hier das Gegenteil -
 * ein Satz aus der 0.9.10, die tatsaechlich noch keinen Endpunkt hatte. Wer
 * ihm folgte, gab mit einer als harmlos angekuendigten Datei die
 * Schluesselgewalt ueber aktion=jetzt weiter.
 *
 * Das Kennwort des Miniservers steht weiterhin NICHT darin: es liegt in der
 * zentralen LoxBerry-Konfiguration und wird von diesem Plugin weder
 * angezeigt noch gespeichert.
 */
function bw_sicherung_bauen()
{
    $kopf = array(
        '_hinweis' => 'Sicherung der Einstellungen des LoxBerry-Plugins Beschattungswaechter. '
                    . 'Zum Zurueckspielen im Reiter Einstellungen. ACHTUNG: die Datei '
                    . 'enthaelt das MERKWORT des unangemeldeten Endpunkts (aktionstoken) '
                    . 'und ist damit vertraulich - wer sie hat, kann Befehle an den '
                    . 'Miniserver ausloesen. Das Kennwort des Miniservers steht NICHT '
                    . 'darin: es liegt in der zentralen LoxBerry-Konfiguration. Ist ein '
                    . 'Wortzeichen der Ecowitt-Weiche eingetragen (wetter_token), steht '
                    . 'auch dieses darin.',
        '_plugin'  => 'beschattungswaechter',
        '_fassung' => bw_fassung(),
        '_stand'   => date('Y-m-d H:i:s'),
    );
    /* X-3 (Verbesserungsbau 30.09.2026): steht in der Konfiguration ein Wert,
       den das eigene Zurueckspielen abweisen wuerde, sagt der Kopf es - nur
       die Namen. Geliefert wird die Sicherung trotzdem vollstaendig; fuer
       diese Schluessel traegt sie die Vorgabe, mit der das Plugin gerade
       arbeitet (bw_config()). */
    $warn = bw_rueckspiel_altwerte();
    if ($warn) {
        $kopf['_warnung'] = 'Gespeicherte Werte, die das Zurueckspielen abweisen wuerde: '
                          . implode(', ', $warn) . '. In dieser Sicherung steht dafuer die '
                          . 'Vorgabe, mit der das Plugin gerade arbeitet.';
    }
    return array_merge($kopf, bw_config());
}

/**
 * Welche GESPEICHERTEN Werte wuerde das eigene Zurueckspielen abweisen? (X-3)
 *
 * Die Konfigurationsdatei wird roh gelesen - bw_config() setzt fuer einen
 * unzulaessigen Wert still die Vorgabe ein, und dann saehe man ihn hier nie.
 * Fehlende Schluessel kommen aus den Vorgaben (die ergaenzt bw_config() beim
 * naechsten Lesen ohnehin). Das Ergebnis geht durch bw_sicherung_lesen() -
 * DIESELBE Funktion wie das Zurueckspielen, keine zweite Liste. Rueckgabe:
 * die Namen, sortiert; leer, wenn alles bestuende oder keine Datei da ist.
 */
function bw_rueckspiel_altwerte()
{
    $p = bw_paths();
    if (!is_file($p['cfgdatei'])) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($p['cfgdatei']), true);
    if (!is_array($d)) {
        return array();
    }
    $probe = bw_vorgaben();
    foreach (array_keys($probe) as $k) {
        if (array_key_exists($k, $d)) {
            $probe[$k] = $d[$k];
        }
    }
    $js = json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($js)) {
        return array();
    }
    $namen = array();
    bw_sicherung_lesen($js, $namen);
    $namen = array_values(array_unique($namen));
    sort($namen);
    return $namen;
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Und seit 0.9.11 wird JEDER WERT geprueft, nicht nur der Schluessel. Eine
 * Datei, deren Schluessel alle bekannt sind, konnte das Plugin bis dahin
 * lautlos stilllegen: von="99:99" ergibt ein Zeitfenster, das nie offen ist.
 *
 * EINE SICHERUNG AUS 0.9.9 ODER 0.9.10 WIRD ABGELEHNT: sie kennt ms_nr und
 * aktionstoken noch nicht, und seit 0.9.16 ist eine unvollstaendige Datei
 * eine Beanstandung (Durchzug vom 07.09.2026; Entscheidung vom 11.09.2026:
 * "die Abweisung bleibt", Regeln/05). Bis 0.9.21 stand hier, alte Dateien
 * blieben lesbar und das geltende Merkwort bleibe stehen, wenn der Schluessel
 * fehlt - beides traf seit 0.9.16 nicht mehr zu, und der Zweig dafuer wurde
 * nie mehr erreicht (C7, Durchgang 30.09.2026; entfernt).
 *
 * DAS MERKWORT: ein LEERES in der Datei heisst "keins gesichert" - dann bleibt
 * das geltende stehen, und die Oberflaeche sagt es (C6). Eine Zahl statt einer
 * Zeichenkette wird abgewiesen (bw_wert_pruefen()).
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 *                  Hinweise[]).
 * Die Hinweise sind KEINE Beanstandungen: sie verhindern das Zurueckspielen
 * nicht, sie erklaeren es. Wer nur drei Werte abholt, bekommt weiter genau
 * das, was er bisher bekam.
 */
function bw_sicherung_lesen($roh, &$namen = null)
{
    /* $namen (X-3): die Schluessel, deren Wert oder Name abgewiesen wurde -
       fuer bw_rueckspiel_altwerte(). Nur Namen, nie Werte. */
    $namen = array();
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(bw_t('TEXT.SICH_KEIN_JSON')), 0, $hinweise);
    }
    $neu = bw_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(bw_t('TEXT.SICH_FREMD'), bw_e($k));
            $namen[] = $k;
            continue;
        }
        if (!bw_wert_pruefen($k, $w)) {
            /* Das Wortzeichen der Weiche erscheint nie in einer Meldung, nur
               seine Laenge (Wetter-1). */
            $mangel[] = sprintf(bw_t('TEXT.SICH_WERT'), bw_e($k),
                bw_e(($k === 'wetter_token' && is_string($w))
                     ? sprintf(bw_t('TEXT.GEHEIM_LAENGE'), strlen($w)) : bw_kurz($w)));
            $namen[] = $k;
            continue;
        }
        $neu[$k] = is_string($w) ? trim($w) : $w;
        $anzahl++;
    }
    /* EIN LEERES MERKWORT HEISST "KEINS GESICHERT" (C6, Durchgang
       30.09.2026). Bis 0.9.21 wurde es uebernommen - "23 Werte uebernommen",
       und danach wies der Endpunkt jede Adresse in Loxone mit 403 ab
       (gemessen, Pruefberichte code C6 und Oberflaeche O10). Jetzt bleibt das
       geltende Merkwort stehen, und die Meldung sagt es. Gilt nur, wenn die
       Datei den Schluessel traegt - ein fehlender ist weiter eine
       Beanstandung (unten). */
    if (array_key_exists('aktionstoken', $daten) && is_string($daten['aktionstoken'])
            && trim($daten['aktionstoken']) === '') {
        $bisher = bw_config(false);
        $bisher = isset($bisher['aktionstoken']) ? trim((string) $bisher['aktionstoken']) : '';
        $neu['aktionstoken'] = $bisher;
        $hinweise[] = bw_t($bisher !== '' ? 'TEXT.SICH_TOKEN_BEHALTEN' : 'TEXT.SICH_TOKEN_KEINS');
    }
    if ($anzahl === 0) {
        $mangel[] = bw_t('TEXT.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    $spaeter = array();
    foreach (array_keys(bw_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            /* Ein Schluessel, den eine fruehere Fassung nicht kannte, darf
               fehlen: dann gilt seine Vorgabe (Wetter-1 ab Werk aus). */
            if (in_array($fk, bw_sicherung_spaeter(), true)) {
                $spaeter[] = $fk;
                continue;
            }
            $fehlend[] = $fk;
        }
    }
    if ($spaeter) {
        $hinweise[] = sprintf(bw_t('TEXT.SICH_SPAETER'),
            htmlspecialchars(implode(', ', $spaeter), ENT_QUOTES, 'UTF-8'));
    }
    if ($fehlend) {
        $mangel[] = sprintf(bw_t('TEXT.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweise);
}

/**
 * Die eingestellte Sprache.
 *
 * Ein Dienst sieht weder LBSystem::lblanguage() noch ein gesetztes LBLANG;
 * die einzige Quelle, die immer da ist, ist Base.Lang in general.json.
 * LBLANG behaelt den Vorrang: wer sie setzt, meint es so.
 */
function bw_sprache()
{
    $s = (string) getenv('LBLANG');
    if ($s === '') {
        $lb = bw_paths()['lbhome'];
        $g = $lb . '/config/system/general.json';
        if ($lb !== '' && is_file($g)) {
            $d = json_decode((string) @file_get_contents($g), true);
            if (isset($d['Base']['Lang'])) {
                $s = (string) $d['Base']['Lang'];
            }
        }
    }
    /* Englisch ist die Rueckfallebene, nicht Deutsch: wer eine fremde
       Sprache eingestellt hat, versteht eher Englisch. */
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

function bw_sprachordner()
{
    $t = getenv('LBPTEMPLATEDIR');
    if ($t !== false && $t !== '') {
        return $t;
    }
    $kand = array();
    $lb = bw_paths()['lbhome'];
    if ($lb !== '') {
        $kand[] = rtrim($lb, '/\\') . '/templates/plugins/' . basename(__DIR__);
        $kand[] = rtrim($lb, '/\\') . '/templates/plugins/' . bw_paths()['plugin'];
    }
    /* Ohne Wurzel nur noch das eigene Archiv. Bis 0.9.19 stand hier ein
       Kandidat drei Stufen ueber dieser Datei - aus einem Archiv unter / war
       das /templates/plugins/html ab der Laufwerkswurzel, und was dort lag,
       uebersetzte die Oberflaeche (in WSL gemessen,
       Pruefung-Beschattungswaechter-0.9.20, Fall T1). Installiert lag er
       unter webfrontend/ und fand nie etwas. */
    $kand[] = dirname(dirname(__DIR__)) . '/templates';
    foreach ($kand as $k) {
        if (is_dir($k . '/lang')) {
            return $k;
        }
    }
    return $kand[count($kand) - 1];
}

/**
 * Uebersetzen - mit Englisch als Rueckfallebene.
 *
 * Erst die englische Datei laden, dann die eingestellte darueberlegen. Ein
 * Schluessel, den nur die englische kennt, erscheint damit englisch statt als
 * roher Schluesselname.
 */
function bw_t($schluessel)
{
    static $tab = null;
    if ($tab === null) {
        $ordner = bw_sprachordner() . '/lang';
        $tab = array();
        $en = @parse_ini_file($ordner . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($en)) {
            $tab = $en;
        }
        $sp = bw_sprache();
        if ($sp !== 'en') {
            $eig = @parse_ini_file($ordner . '/language_' . $sp . '.ini', true, INI_SCANNER_RAW);
            if (is_array($eig)) {
                $tab = array_replace_recursive($tab, $eig);
            }
        }
    }
    $teil = explode('.', $schluessel, 2);
    if (count($teil) === 2 && isset($tab[$teil[0]][$teil[1]])) {
        return $tab[$teil[0]][$teil[1]];
    }
    return $schluessel;
}


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function bw_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = bw_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. Bis 0.9.21 sagte
     * dieser Kommentar das, und der Code darunter tat das Gegenteil: erst
     * wurde der Inhalt mit den Rechten der umask geschrieben, danach kam
     * chmod (C8, Durchgang 30.09.2026). Jetzt derselbe Weg wie jede
     * Konfiguration. */
    bw_text_schreiben($datei, $neu, 0600);
    $wort = $neu;
    return $wort;
}

function bw_formtoken()
{
    $grund = bw_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function bw_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(bw_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function bw_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = bw_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return bw_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return bw_t('WACHE.FALSCH');
    }
    return '';
}

/* ==================================================================
 * A. DIE FELDER - EINE QUELLE FUER ALLE WEGE
 * ==================================================================
 *
 * Statuszeile, MQTT-Themen, Feldtabelle im Reiter Loxone und die erzeugte
 * Importdatei nehmen alle DIESE Liste. Drei getrennte Listen fuer dieselbe
 * Sache laufen auseinander, und niemand merkt es: bei der Waermepumpe legte
 * die Vorlage 20 Eingaenge an, die Zeile lieferte 17 und MQTT 15 - die drei
 * fehlenden waren ausgerechnet die Arbeitszahl.
 *
 * Spalten: bez (Sprachschluessel der ERKLAERUNG), kurz (Sprachschluessel des
 * NAMENS), einheit, min, max, zeile (0 = nur ueber MQTT und aktion=json).
 *
 * WARUM ZWEI TEXTE JE FELD, seit 0.9.14: der Comment einer Importvorlage
 * wird in Loxone Config zum ANZEIGENAMEN des Bausteins, nicht zu seiner
 * Dokumentation. Bis 0.9.13 stand dort die Erklaerung, und die ist ein Satz:
 * am 05.09.2026 an der eingelesenen Projektdatei gemessen trugen 8 von 14
 * Bausteinen einen Namen ueber 40 Zeichen, BW OK einen mit 161 - dieselbe
 * Zahl wie im Anlassfall APC-UPS 1.1.6. Zum Vergleich in derselben Datei:
 * 100 Bausteine anderer Linien, Namen im Mittel 20 Zeichen lang.
 *
 * 'kurz' ist der Name (hoechstens 30 Zeichen), 'bez' bleibt die Erklaerung
 * fuer die Feldtabelle im Reiter, die Themenliste und die Hilfe.
 *
 * NEUE FELDER WERDEN HINTEN ANGEHAENGT. Loxone sucht den Suchtext woertlich
 * und nimmt den ERSTEN Treffer der Zeile; die Reihenfolge ist damit Teil der
 * Zusage an bestehende Anlagen.
 * ================================================================== */
function bw_felder()
{
    return array(
        'OK'       => array('bez' => 'FELD.OK',       'kurz' => 'FELDKURZ.OK',       'einheit' => '',  'min' => 0,  'max' => 1,      'zeile' => 1),
        'AKTIV'    => array('bez' => 'FELD.AKTIV',    'kurz' => 'FELDKURZ.AKTIV',    'einheit' => '',  'min' => 0,  'max' => 1,      'zeile' => 1),
        'FENSTER'  => array('bez' => 'FELD.FENSTER',  'kurz' => 'FELDKURZ.FENSTER',  'einheit' => '',  'min' => 0,  'max' => 1,      'zeile' => 1),
        'ZIELE'    => array('bez' => 'FELD.ZIELE',    'kurz' => 'FELDKURZ.ZIELE',    'einheit' => '',  'min' => 0,  'max' => 6,      'zeile' => 1),
        'GESENDET' => array('bez' => 'FELD.GESENDET', 'kurz' => 'FELDKURZ.GESENDET', 'einheit' => '',  'min' => 0,  'max' => 999999, 'zeile' => 1),
        'FEHLER'   => array('bez' => 'FELD.FEHLER',   'kurz' => 'FELDKURZ.FEHLER',   'einheit' => '',  'min' => 0,  'max' => 9999,   'zeile' => 1),
        'CODE'     => array('bez' => 'FELD.CODE',     'kurz' => 'FELDKURZ.CODE',     'einheit' => '',  'min' => 0,  'max' => 599,    'zeile' => 1),
        'ALTER'    => array('bez' => 'FELD.ALTER',    'kurz' => 'FELDKURZ.ALTER',    'einheit' => 's', 'min' => -1, 'max' => 999999, 'zeile' => 1),
        'ZAEHLER'  => array('bez' => 'FELD.ZAEHLER',  'kurz' => 'FELDKURZ.ZAEHLER',  'einheit' => '',  'min' => -1, 'max' => 999,    'zeile' => 1),
        'SCHARF'   => array('bez' => 'FELD.SCHARF',   'kurz' => 'FELDKURZ.SCHARF',   'einheit' => '',  'min' => -1, 'max' => 99,     'zeile' => 1),
        'AUTOMATIKEN' => array('bez' => 'FELD.AUTOMATIKEN', 'kurz' => 'FELDKURZ.AUTOMATIKEN', 'einheit' => '', 'min' => -1, 'max' => 99, 'zeile' => 1),
        /* Neu 0.9.22 (O5), HINTEN angehaengt: das Alter des letzten
           Durchgangs. Daran haengt die Ausfallerkennung der Baustein-Liste,
           nicht an ALTER - das ist das Alter des letzten BEFEHLS und nachts
           regulaer viele Stunden alt. Die Grenze ist weit: ein Takt, der
           tagelang steht, darf in Loxone nicht an MaxVal auf 0 fallen und
           damit wie ein frischer aussehen (Regeln/07, "MaxVal ist eine
           Validierungsgrenze"). Geht wie ALTER nur ueber HTTP (bw_nur_http()). */
        'LAUFALTER' => array('bez' => 'FELD.LAUFALTER', 'kurz' => 'FELDKURZ.LAUFALTER', 'einheit' => 's', 'min' => -1, 'max' => 99999999, 'zeile' => 1),
    );
}

/**
 * Der Suchtext fuer die Befehlserkennung eines virtuellen Eingangs.
 *
 * DAS FUEHRENDE SEMIKOLON IST PFLICHT. Loxone sucht woertlich und nimmt den
 * ersten Treffer: ohne den Trenner faende das Muster fuer ALTER in dieser
 * Zeile zuerst den Namen, der auf ALTER endet. In dieser Sammlung ist das
 * dreimal aufgelaufen, zuletzt mit zwei Feldern, deren einer Name im anderen
 * steckt.
 *
 * Er steht an EINER Stelle. Die Importdatei, die Feldtabelle und die
 * Baustein-Liste holen ihn hier - kein Suchtext als Fliesstext in einer
 * Sprachdatei: 540 solche Abschriften stehen im Bestand, und eine berichtigte
 * Abschrift ist immer noch eine Abschrift.
 */
function bw_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/**
 * Die Werte aller Felder - die eine Quelle, aus der beide Wege schoepfen.
 *
 * ALTER wird zur LESEZEIT gerechnet, nicht beim Schreiben eingefroren: sonst
 * kann ein drei Stunden altes Abbild bei totem Dienst nicht von einer
 * frischen Messung unterschieden werden.
 */
function bw_werte(?array $c = null, ?array $stand = null)
{
    if ($c === null) { $c = bw_config(); }
    if ($stand === null) { $stand = bw_stand_lesen(); }
    $lauf = bw_lauf_lesen();
    $letzte = isset($stand['letzte']) ? (int) $stand['letzte'] : 0;
    /* Das Alter des LAUFS, zur Lesezeit gerechnet (O5): Sekunden seit dem
       letzten Durchgang des Fuenfminutentakts, -1 wenn noch keiner lief. */
    $laufts = (int) $lauf['ts'];
    $laufalter = $laufts > 0 ? max(0, time() - $laufts) : -1;
    /* OK HAT EINE ALTERSGRENZE (C1, Entscheidung 4 vom 29.09.2026): 0,
       sobald der letzte Durchgang aelter ist als das Dreifache des
       Cron-Takts. Bis 0.9.21 stand hier nur das Feld ok aus lauf.json - ein
       seit zwei Stunden stehender Takt lieferte weiter OK=1 (gemessen,
       Pruefbericht code C1). Und 0, solange die eingeschaltete Zaehlung
       zuletzt misslungen ist (C3, Frage 6/11). ALTER bleibt, was es ist: das
       Alter des letzten BEFEHLS. */
    $ok = (!empty($lauf['ok']) && $laufalter >= 0 && $laufalter <= bw_ok_grenze()
           && !bw_zaehlung_misslungen($c, $stand)) ? 1 : 0;
    /* OHNE MESSUNG EIN STRICH (C3, Entscheidung 5 vom 29.09.2026): nie
       gemessen oder die Zaehlung abgeschaltet - dann gibt es keine Aussage,
       und eine -1 oder ein stehengebliebener Altwert saehe aus wie eine. Bis
       0.9.21 ging hier -1 bzw. der letzte gemessene Wert hinaus, auch nach
       dem Abschalten. */
    $gemessen = bw_zaehlung_gilt($c, $stand);
    return array(
        'OK'          => $ok,
        'AKTIV'       => empty($c['aktiv']) ? 0 : 1,
        'FENSTER'     => bw_im_fenster($c) ? 1 : 0,
        'ZIELE'       => count(bw_ziele($c)),
        'GESENDET'    => isset($stand['gesendet']) ? (int) $stand['gesendet'] : 0,
        'FEHLER'      => isset($stand['fehler']) ? (int) $stand['fehler'] : 0,
        'CODE'        => isset($stand['code']) ? (int) $stand['code'] : 0,
        'ALTER'       => $letzte > 0 ? (time() - $letzte) : -1,
        'ZAEHLER'     => isset($lauf['zaehler']) ? (int) $lauf['zaehler'] : -1,
        'SCHARF'      => $gemessen ? (int) $stand['scharf'] : '-',
        'AUTOMATIKEN' => $gemessen ? (int) $stand['automatiken'] : '-',
        'LAUFALTER'   => $laufalter,
    );
}

/**
 * Die Grenze fuer OK: dreimal der Cron-Takt von 300 s (Entscheidung 4 vom
 * 29.09.2026, C1). Eine Stelle fuer den Endpunkt, MQTT, die Pruefzeile im
 * Reiter Test und die Schwelle der Baustein-Liste.
 */
function bw_ok_grenze()
{
    return 3 * 300;
}

/**
 * Die Felder, die nur ueber HTTP hinausgehen: sie aendern sich jede Sekunde
 * und machten jeden Doppelt-senden-Filter wirkungslos; ueber MQTT sagt der
 * Zeitstempel dasselbe, und der Miniserver rechnet selbst.
 */
function bw_nur_http()
{
    return array('ALTER', 'LAUFALTER');
}

/** Gilt die Zaehlung? Eingeschaltet UND schon einmal gemessen (C3). */
function bw_zaehlung_gilt(array $c, array $stand)
{
    return !empty($c['pruefen_ein'])
        && isset($stand['scharf'], $stand['automatiken'])
        && (int) $stand['scharf'] >= 0 && (int) $stand['automatiken'] >= 0;
}

/** Ist die eingeschaltete Zaehlung zuletzt misslungen? (C3, Frage 6/11) */
function bw_zaehlung_misslungen(array $c, array $stand)
{
    return !empty($c['pruefen_ein']) && !empty($stand['zaehlung_fehler']);
}

/**
 * Das Ergebnis einer Zaehlung in den Stand eintragen - EINE Stelle fuer den
 * Lauf, den Endpunkt (aktion=pruefen) und den Knopf im Reiter Test (C3).
 *
 * Gelungen: Werte, Zeitpunkt, der Fehlschlag ist erledigt. Misslungen: die
 * zuletzt gezaehlten Werte bleiben stehen (Frage 6/11), vermerkt wird nur der
 * Fehlschlag - OK geht darueber auf 0 (bw_werte()). Bis 0.9.21 stand ein
 * Fehlschlag allein im Protokoll, und OK blieb 1 (gemessen, Pruefbericht
 * code C3a).
 */
function bw_zaehlung_eintragen(array $stand, $ok, array $erg)
{
    if ($ok) {
        $stand['scharf'] = (int) $erg['scharf'];
        $stand['automatiken'] = (int) $erg['gesamt'];
        $stand['scharf_ts'] = time();
        $stand['zaehlung_fehler'] = 0;
    } else {
        $stand['zaehlung_fehler'] = 1;
    }
    return $stand;
}

/**
 * Der Stand nach einem Befehl an alle Ziele - EINE Rechnung fuer den Lauf,
 * den Endpunkt (aktion=jetzt) und den Knopf "Befehl jetzt senden" (C2,
 * Durchgang 30.09.2026).
 *
 * Bis 0.9.21 rechnete jeder der drei Wege selbst: der Knopf nahm den Code
 * des LETZTEN Ziels und setzte letzte_ok schon bei einem Teilerfolg, der
 * Endpunkt ebenso letzte_ok - gemessen mit zwei Zielen, das erste mit HTTP
 * 500: Knopf {"letzte_ok":<jetzt>,"fehler":1,"code":200}, Lauf
 * {"letzte_ok":0,"fehler":1,"code":500} (Pruefbericht code C2). Es gilt die
 * Rechnung des Laufs: der Code des ERSTEN Fehlschlags, erst wenn keiner
 * fehlschlug der des letzten guten Ziels; letzte_ok nur, wenn ALLE Ziele
 * angenommen haben.
 *
 * $ergebnisse: die Rueckgaben von bw_senden(), in der Reihenfolge der Ziele.
 */
function bw_stand_nach_senden(array $stand, array $ergebnisse)
{
    $gut = 0;
    $code_fehler = 0;
    $code_gut = 0;
    foreach ($ergebnisse as $r) {
        if (!empty($r['ok'])) {
            $gut++;
            $code_gut = isset($r['code']) ? (int) $r['code'] : 0;
            continue;
        }
        if ($code_fehler === 0) {
            $code_fehler = isset($r['code']) ? (int) $r['code'] : 0;
        }
    }
    $alles = ($ergebnisse && $gut === count($ergebnisse));
    $jetzt = time();
    $stand['letzte'] = $jetzt;
    $stand['letzte_ok'] = $alles ? $jetzt : (isset($stand['letzte_ok']) ? (int) $stand['letzte_ok'] : 0);
    $stand['gesendet'] = (isset($stand['gesendet']) ? (int) $stand['gesendet'] : 0) + 1;
    $stand['fehler'] = $alles ? 0 : ((isset($stand['fehler']) ? (int) $stand['fehler'] : 0) + 1);
    $stand['code'] = $alles ? $code_gut : $code_fehler;
    return $stand;
}

/** Die Antwortzeile fuer den Miniserver. */
function bw_statuszeile(?array $c = null, ?array $stand = null)
{
    $z = 'BW';
    $felder = bw_felder();
    foreach (bw_werte($c, $stand) as $k => $v) {
        if (empty($felder[$k]['zeile'])) { continue; }
        $z .= ';' . $k . '=' . $v;
    }
    return $z;
}


/* ==================================================================
 * B. DAS LEBENSZEICHEN
 * ==================================================================
 *
 * Ein virtueller Eingang behaelt seinen letzten Wert, bei MQTT mit Retain
 * sogar ueber einen Neustart des Miniservers hinweg. Faellt der Cron-Lauf
 * aus, steht in Loxone weiter "eingeschaltet, alles in Ordnung" - das ist
 * keine fehlende Auskunft, sondern eine Falschaussage, und sie sieht aus wie
 * eine richtige.
 *
 * Der ZAEHLER beantwortet, was der Zeitstempel nicht kann: ein Raspberry ohne
 * Echtzeituhr springt beim ersten Zeitabgleich, und ein Alter kann danach
 * negativ oder stundenlang sein, obwohl alles laeuft. Eine umlaufende Zahl
 * nicht. Die -1 ist noetig, weil 0 ein gueltiger Stand waere.
 *
 * Es liegt im DATENverzeichnis: der unangemeldete Endpunkt darf dort nichts
 * schreiben, und die Sicherung soll es nicht mitschleppen.
 * ================================================================== */
function bw_lauf_lesen()
{
    $p = bw_paths();
    $d = json_decode((string) @file_get_contents($p['datadir'] . '/lauf.json'), true);
    if (!is_array($d)) { $d = array(); }
    return array(
        'ts'      => isset($d['ts']) ? (int) $d['ts'] : 0,
        'zaehler' => isset($d['zaehler']) ? (int) $d['zaehler'] : -1,
        'ok'      => isset($d['ok']) ? (int) $d['ok'] : 0,
    );
}

/**
 * Das Lebenszeichen fortschreiben.
 *
 * $takt = false fuer alles, was NICHT der Cron ist - den Endpunkt und den
 * Knopf im Reiter Test. Beide sagen etwas ueber den Erfolg des Befehls, aber
 * NICHTS darueber, ob der Fuenfminutenlauf noch geht.
 *
 * Bis 0.9.12 schrieben sie den ganzen Satz. Loeste Loxone stuendlich
 * aktion=jetzt aus, liefen Zeitstempel und Zaehler weiter, auch wenn der Cron
 * seit Tagen nicht mehr startete - und bw_befund() haengt mit "STEHT" an
 * genau diesem Zeitstempel. Damit beantwortete die Zaehlung die eine Frage
 * falsch, fuer die es sie gibt.
 */
/**
 * Die Marke aus postinstall.sh wegnehmen: der erste Takt nach einem Update ist
 * gelaufen (I4). Bis dahin meldet bw_befund() einen Hinweis statt "noch nie
 * gelaufen" - purge_installation hat lauf.json mit dem Datenordner geloescht.
 */
function bw_nach_update_merker_weg()
{
    $f = bw_paths()['datadir'] . '/erster_takt_nach_update';
    if (is_file($f)) {
        @unlink($f);
    }
}

function bw_lauf_schreiben($ok, $takt = true)
{
    $p = bw_paths();
    $alt = bw_lauf_lesen();
    if (!$takt) {
        $neu = array('ts' => (int) $alt['ts'], 'zaehler' => (int) $alt['zaehler'],
                     'ok' => $ok ? 1 : 0);
        bw_json_schreiben($p['datadir'] . '/lauf.json', $neu, 0644);
        return $neu;
    }
    $z = (int) $alt['zaehler'];
    $z = ($z < 0) ? 0 : (($z + 1) % 1000);
    $neu = array('ts' => time(), 'zaehler' => $z, 'ok' => $ok ? 1 : 0);
    bw_json_schreiben($p['datadir'] . '/lauf.json', $neu, 0644);
    return $neu;
}


/* ==================================================================
 * C. MQTT - DER REGELWEG
 * ==================================================================
 *
 * Gesendet wird als UDP an den Eingangsport des Gateways, so wie im ganzen
 * Haus. Der Gateway ist KEIN Plugin, sondern seit LoxBerry 3 Bestandteil des
 * Systems.
 * ================================================================== */

/**
 * Zustand UND Fassung des MQTT-Gateways.
 *
 * Die Fassung steht als Mqtt.Gatewayversion in general.json (ab Werk 1). Sie
 * entscheidet, was der Anwender eintragen muss:
 *   V1  Das Abo wird VON HAND eingetragen - ohne den Eintrag kommt am
 *       Miniserver nichts an. Das ist die haeufigste Fehlerursache ueberhaupt.
 *   V2  Der Kern erkennt die Themengruppe selbst und schaltet auf der
 *       Abonnement-Seite die Eintragknoepfe ab; angehakt werden nur noch die
 *       gewuenschten Datenpunkte.
 *
 * Gemessen am LoxBerry-Kern (webfrontend/htmlauth/system/mqtt-gateway.cgi):
 *     $gatewayversion = $generaljson->{Mqtt}->{Gatewayversion} // 1;
 *     $template->param("FORM_DISABLE_BUTTONS", 1) if $gatewayversion == 2;
 *
 * Rueckgabe null, wenn general.json nicht lesbar ist. Die Fassung 0 heisst
 * "unbekannt" und wird NICHT auf 1 vorbelegt: "unbekannt" und "Fassung 1"
 * sind verschiedene Aussagen, und die Oberflaeche behandelt sie verschieden.
 */
function bw_mqtt_gateway_info()
{
    $p = bw_paths();
    if ($p['lbhome'] === '') { return null; }
    $d = @json_decode((string) @file_get_contents(
             $p['lbhome'] . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return null; }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    $udp = 0;
    if (isset($d['Mqtt']['Udpinport'])) { $udp = (int) $d['Mqtt']['Udpinport']; }
    return array(
        /* Der Schluessel heisst Gatewayautostart, nicht Autostart. Der
           erfundene Name hat in fuenf Linien dieses Hauses dazu gefuehrt,
           dass die Warnung auf JEDER einwandfrei eingerichteten Anlage
           erschien - eine Anzeige, die immer dasselbe sagt, sagt nichts. */
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($d['Mqtt']['Gatewayversion'])
                       ? (int) $d['Mqtt']['Gatewayversion'] : 0,
        'udpport'   => ($udp > 0 && $udp < 65536) ? $udp : 0,
    );
}

/**
 * Den Themen-Praefix unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE, mit dem Leerzeichen als Trenner zwischen
 * Thema und Wert. Ein Praefix mit Leerzeichen oder Zeilenumbruch erzeugt
 * damit erfundene Themen. Die Wache steht hier ein zweites Mal, weil sie hier
 * nichts kostet und den Weg unabhaengig von jedem Aufrufer schliesst - eine
 * zurueckgespielte Sicherung geht am Formular vorbei.
 */
function bw_mqtt_praefix($roh)
{
    $s = preg_replace('#[^\w/\-]#', '', (string) $roh);
    $s = trim((string) $s, '/');
    return $s !== '' ? $s : 'beschattung';
}

/** Ein Wert, der in eine ZEILE geht, darf keine Zeilenumbrueche tragen. */
function bw_mqtt_wert($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim((string) preg_replace('/ {2,}/', ' ', $wert));
}

function bw_mqtt_senden($port, array $zeilen)
{
    if (!$zeilen || (int) $port < 1) { return 0; }
    /* stream_socket_client() gehoert zum Kern; socket_create() steckt in
       einer Erweiterung, die nicht garantiert geladen ist - und ihr Fehlen
       ist kein abfangbarer, sondern ein toedlicher Fehler. Im Cron, der nach
       /dev/null schreibt, sieht das niemand. */
    $fp = @stream_socket_client('udp://127.0.0.1:' . (int) $port, $eno, $etxt, 2);
    if (!$fp) {
        bw_log_wenn_neu('mqttport',
            'MQTT: der UDP-Eingang des Gateways ist nicht erreichbar (Port '
            . (int) $port . '): ' . (string) $etxt);
        return 0;
    }
    stream_set_timeout($fp, 2);
    $n = 0;
    foreach ($zeilen as $z) {
        if (@fwrite($fp, $z) !== false) { $n++; }
    }
    fclose($fp);
    return $n;
}


/**
 * Geht dieses Thema zurueckbehalten (retained) hinaus?
 *
 * Hausstandard seit 03.09.2026 (Regeln/07): **Zustaende** werden retained
 * gesendet, damit Loxone nach einem Neustart des Miniservers oder des
 * Brokers sofort den Stand hat; **Messwerte mit Zeitbezug** nicht, damit
 * kein alter Wert als aktueller erscheint; das **Lebenszeichen** nie.
 *
 * Die Entscheidung faellt je THEMA und nicht je Aufruf. Das ist keine
 * Formsache: bw_mqtt_publish() schickt Zustaende und Lebenszeichen in EINEM
 * Durchgang hinaus. Wer am Aufruf entscheidet, macht damit entweder das
 * Lebenszeichen retained (falsch - retained zeigte es fuer immer "lebt")
 * oder die Zustaende nicht (auch falsch). Aufgelaufen bei ACTiKamera 1.9.19
 * am 08.09.2026, und genau daran haengt hier die Aufteilung.
 *
 * Zurueckbehalten wird, was nach einem Neustart sofort wieder stimmen soll
 * UND nach dem Ende der Laeufe wahr bleibt: die Schalterstellung (aktiv), die
 * Zahl der Ziele, der Zaehler der gesendeten Befehle seit dem letzten Update
 * (ein Verlaufszaehler - er bleibt wahr, auch wenn der Dienst stirbt;
 * Entscheidung vom 24.09.2026) und die am Miniserver gemessenen Automatiken
 * (scharf, automatiken - eine Aussage ueber die Anlage, nicht ueber den
 * Dienst).
 *
 * Fluechtig gehen seit 0.9.20 auch ok, fehler, code und fenster (Regeln/07,
 * Abschnitt 3, Entscheidungen vom 18., 19. und 24.09.2026):
 *   ok, fehler, code  sagen, was der DIENST ueber sich selbst feststellt:
 *                     Durchgang ohne Stoerung, Fehler in Folge, HTTP-Antwort
 *                     auf den eigenen Befehl. Stirbt der Dienst, stuenden sie
 *                     zurueckbehalten fuer immer als "in Ordnung" da.
 *   fenster           wird allein durch die Uhr falsch: ein Vollversand geht
 *                     nur im Zeitfenster hinaus, zurueckbehalten stand also
 *                     nachts "1" im Broker.
 * Fluechtig bleiben die Teile des Lebenszeichens - der umlaufende ZAEHLER
 * (zweimal: als eigenes Thema und unter status/), der Zeitstempel und
 * status/ok (seit 0.9.19, Entscheidung des Hausherrn vom 17.09.2026).
 * Regeln/07: "Das Lebenszeichen ist nie retained ... es traegt den
 * Zeitstempel." Die Altwerte raeumt bw_mqtt_publish() ab, solange
 * bw_mqtt_altlast() sie meldet.
 *
 * ALTER geht ueber MQTT gar nicht hinaus und steht deshalb nicht in der
 * Tabelle.
 *
 * Ein Thema OHNE Eintrag geht fluechtig. Das ist die sichere Richtung: ein
 * nicht zurueckbehaltener Zustand ist unbequem, ein zurueckbehaltener Wert,
 * an den niemand gedacht hat, bleibt fuer immer im Broker stehen.
 */
function bw_mqtt_retain($thema)
{
    static $tab = null;
    if ($tab === null) {
        $tab = array();
        foreach (array(
            'aktiv', 'ziele', 'gesendet',
            'scharf', 'automatiken',
        ) as $t) { $tab[$t] = true; }
    }
    return isset($tab[(string) $thema]);
}

/**
 * Eine fertige UDP-Zeile fuer ein Thema - mit dem richtigen Befehlswort.
 *
 * Ueber das MQTT-Gateway V1 heisst der Befehl fuer ein zurueckbehaltenes
 * Thema "retain" statt "publish" (mqttgateway.pl:293 und :354-357, am Geraet
 * gemessen). Der UDP-Eingang kennt genau vier Woerter; ein unbekanntes
 * erstes Wort wuerde als Thema gelesen.
 *
 * Ein LEERER Wert geht immer fluechtig hinaus, auch wenn die Tabelle retain
 * sagt: eine leere Nutzlast LOESCHT ein zurueckbehaltenes Thema im Broker
 * ("Delete $udptopic from memory because of empty message"). Hier kann das
 * nur ein CODE ohne Antwort oder ein leergeraeumter Zaehler sein - der Fall
 * ist selten, aber sein Ergebnis waere ein Thema, das aus Loxone
 * verschwindet, statt einen Wert zu tragen.
 */
function bw_mqtt_zeile($praefix, $thema, $wert)
{
    $w = bw_mqtt_wert($wert);
    $befehl = (bw_mqtt_retain($thema) && $w !== '') ? 'retain' : 'publish';
    return $befehl . ' ' . $praefix . '/' . $thema . ' ' . $w;
}

/**
 * Alle Werte veroeffentlichen - und die drei Lebenszeichen dazu.
 *
 * Die Lebenszeichen gehen AN DER SIGNATUR VORBEI und bei jedem Durchgang
 * hinaus. Ein ALTER in der Signatur machte den Doppelt-senden-Filter
 * wirkungslos: der Wert aendert sich jede Sekunde, und der Cron schickte
 * jedes Mal alles. Ueber MQTT gibt es ohnehin kein Alter, nur einen
 * Zeitstempel - der Miniserver rechnet selbst:
 * Alter = (Loxone-Zeit + 1230768000) - ts.
 */
function bw_mqtt_publish(?array $c = null, ?array $stand = null)
{
    if ($c === null) { $c = bw_config(); }
    if (empty($c['mqtt_ein'])) { return 0; }
    $gw = bw_mqtt_gateway_info();
    if ($gw === null || $gw['udpport'] === 0) {
        bw_log_wenn_neu('mqttgw',
            'MQTT: in der general.json steht kein brauchbarer UDP-Eingangsport '
            . '- ist das MQTT-Gateway eingerichtet?');
        return 0;
    }
    $w = bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '');
    $werte = bw_mqtt_werte($c, $stand);
    /* Die Altwerte, die der Broker noch haelt (oder alle, wenn er nicht zu
       fragen war), bekommen eine leere retain-Nutzlast UNMITTELBAR vor ihrem
       gueltigen Wert - in derselben Verbindung, als Nachbarzeile (Fall N3).
       Jedes der fuenf Altthemen hat in jedem Vollversand einen Wert (OK,
       FENSTER, FEHLER, CODE aus bw_werte(), status/ok aus dem Lauf); eine
       leere Nachricht ohne Wert dahinter entsteht hier also nicht. */
    $weg = array_flip(bw_mqtt_altlast($w)['themen']);
    $zeilen = array();
    foreach ($werte as $t => $v) {
        if (isset($weg[$t])) {
            /* Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: genau
               die Form, die das Gateway als Loeschung liest (Regeln/07,
               Nachtrag 19.09.2026: mqttgateway.pl:281, :311-315, :357). */
            $zeilen[] = 'retain ' . $w . '/' . $t . ' ';
        }
        $zeilen[] = bw_mqtt_zeile($w, $t, $v);
    }
    $n = bw_mqtt_senden($gw['udpport'], $zeilen);
    if ($n > 0) {
        /* Der Vollversand traegt auch die drei Zustaende; ihr Merker fuer den
           Aenderungsversand zieht mit (M1). */
        bw_mqtt_zustand_merken($w, $werte);
    }
    return $n;
}

/**
 * Die Werte eines Vollversands, Thema (ohne Praefix) => Wert - EINE Quelle
 * fuer das Senden und fuer die Pruefzeile "Themenliste gegen Sendecode" im
 * Reiter Test (O3), die damit nichts senden muss, um zu messen.
 *
 * Was nur ueber HTTP geht (bw_nur_http()), fehlt; die drei Lebenszeichen
 * kommen aus lauf.json dazu.
 */
function bw_mqtt_werte(array $c, ?array $stand = null)
{
    $werte = array();
    foreach (bw_werte($c, $stand) as $k => $v) {
        if (in_array($k, bw_nur_http(), true)) { continue; }
        $werte[strtolower($k)] = $v;
    }
    $lauf = bw_lauf_lesen();
    $werte['status/ts'] = (int) $lauf['ts'];
    $werte['status/zaehler'] = (int) $lauf['zaehler'];
    $werte['status/ok'] = (int) $lauf['ok'];
    return $werte;
}

/** Die Themen des Aenderungsversands (M1) - eine Liste fuer Senden und Pruefzeile. */
function bw_mqtt_zustaende_themen()
{
    return array('aktiv', 'ziele', 'fenster');
}

/**
 * Die drei Zustaende aktiv, ziele und fenster - nach jedem Lauf, wenn sie sich
 * geaendert haben, und nach Speichern oder Zurueckspielen (M1, Durchgang
 * 30.09.2026).
 *
 * Bis 0.9.21 gingen sie nur im Vollversand hinaus, und den gibt es nur mit
 * einem Befehl: im Zeitfenster und hoechstens einmal je Abstand. Wer den
 * Waechter abschaltete, las ueber MQTT dauerhaft aktiv 1 - zurueckbehalten,
 * also auch nach jedem Neustart von Broker oder Gateway; fenster ging
 * ausserhalb des Zeitfensters nie hinaus und stand damit die ganze Nacht auf 1
 * (gemessen, Pruefbericht code M1).
 *
 * Gesendet wird, wenn sich ein Wert oder das Praefix gegenueber dem letzten
 * Versand geaendert hat, wenn $erzwingen gesetzt ist - und sonst hoechstens
 * alle 30 Minuten einmal (Regeln/07, "Aenderungen und den vollen Satz in
 * grobem Takt"): der UDP-Eingang des Gateways verwirft unter Last Datagramme,
 * und ein verlorenes "fenster 0" bliebe sonst bis zum naechsten Morgen stehen.
 * Die Werte kommen aus bw_werte(), die Befehlswoerter aus bw_mqtt_retain() -
 * aktiv und ziele retained, fenster fluechtig.
 */
function bw_mqtt_zustaende(?array $c = null, $erzwingen = false)
{
    if ($c === null) { $c = bw_config(); }
    if (empty($c['mqtt_ein'])) { return 0; }
    $gw = bw_mqtt_gateway_info();
    if ($gw === null || $gw['udpport'] === 0) { return 0; }
    $w = bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '');
    $alle = bw_werte($c);
    $werte = array();
    foreach (bw_mqtt_zustaende_themen() as $t) {
        $werte[$t] = $alle[strtoupper($t)];
    }
    $alt = bw_mqtt_zustand_lesen();
    $jetzt = time();
    if (!$erzwingen && $alt['praefix'] === $w && $alt['werte'] === $werte
        && $alt['ts'] <= $jetzt && $jetzt - $alt['ts'] < 1800) {
        return 0;
    }
    $zeilen = array();
    foreach ($werte as $t => $v) {
        $zeilen[] = bw_mqtt_zeile($w, $t, $v);
    }
    $n = bw_mqtt_senden($gw['udpport'], $zeilen);
    if ($n > 0) {
        bw_mqtt_zustand_merken($w, $werte);
    }
    return $n;
}

/** Der Merker des Aenderungsversands: Praefix, Werte, Zeitpunkt (Datenordner). */
function bw_mqtt_zustand_lesen()
{
    $aus = array('praefix' => '', 'werte' => array(), 'ts' => 0);
    $f = bw_paths()['datadir'] . '/mqtt_zustand.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($d)) {
        $aus['praefix'] = (isset($d['praefix']) && is_string($d['praefix'])) ? $d['praefix'] : '';
        $aus['werte'] = (isset($d['werte']) && is_array($d['werte'])) ? $d['werte'] : array();
        $aus['ts'] = isset($d['ts']) ? (int) $d['ts'] : 0;
    }
    return $aus;
}

function bw_mqtt_zustand_merken($praefix, array $werte)
{
    $m = array();
    foreach (bw_mqtt_zustaende_themen() as $t) {
        if (!array_key_exists($t, $werte)) { return false; }
        $m[$t] = $werte[$t];
    }
    return bw_json_schreiben(bw_paths()['datadir'] . '/mqtt_zustand.json',
        array('praefix' => (string) $praefix, 'werte' => $m, 'ts' => time()), 0644);
}

/**
 * Die Themen, deren zurueckbehaltener ALTWERT abgeraeumt werden muss.
 *
 * Eine Umstellung von retain auf publish loescht nichts: der alte Wert steht
 * im Broker weiter und wird nach jedem Neustart von Broker oder Gateway
 * wieder ausgeliefert. status/ok ging in 0.9.18 retained hinaus, ok,
 * fenster, fehler und code bis 0.9.19. Abgeraeumt wird nach der Antwort
 * des Brokers (bw_mqtt_altlast()).
 */
function bw_mqtt_altlast_liste()
{
    return array('status/ok', 'ok', 'fenster', 'fehler', 'code');
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung, ein SUBSCRIBE mit allen Filtern.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok' heisst: der Broker hat die Anmeldung (CONNACK 0) und JEDEN Filter
 * (SUBACK-Rueckgabe unter 0x80) bestaetigt; was dann nicht unter 'belegt'
 * steht, ist leer. 'unbekannt': er war nicht zu fragen (keine Wurzel, keine
 * general.json, keine Verbindung, Anmeldung abgewiesen, Filter abgelehnt,
 * keine Antwort).
 *
 * Warum ueberhaupt fragen: das Abraeumen laeuft ueber den UDP-Eingang des
 * Gateways, und dort meldet fwrite() auch fuer ein verworfenes Datagramm
 * Erfolg (Regeln/07, "Ein Absender merkt nichts davon", Nachtrag vom
 * 19.09.2026 - der Anlass war diese Linie: der Merker der 0.9.19 stand, und
 * beschattung/status/ok 1 lag am Geraet weiter im Broker). Belegt ist das
 * Abraeumen erst, wenn der Broker selbst sagt, dass nichts mehr dasteht.
 *
 * MQTT 3.1.1 von Hand, nur CONNECT, SUBSCRIBE (QoS 0) und DISCONNECT - ohne
 * fremde Bibliothek; Bauart ko_mqtt_behalten_liste() (KODI-NG 1.2.10), dort
 * aus tb_mqtt_behalten_liste() (Spotpreis-Tibber 0.9.19). Anders als dort wird
 * die SUBACK-Rueckgabe gelesen: ein Broker, der das Lesen verweigert (0x80),
 * schickt danach nichts - ungeprueft hiesse das "nichts belegt", und der
 * Merker laege auf einer Antwort, die keine war (in WSL gemessen,
 * Pruefung-Beschattungswaechter-0.9.21, Fall N17). Belegt ist ein Thema nur
 * am EMPFANGENEN Paket mit Retain-Merkmal und nicht leerer Nutzlast. Die
 * Anmeldung nimmt Brokeruser/Brokerpass aus der general.json (Regeln/07,
 * Abschnitt 2); das Kennwort steht nur im CONNECT-Paket, nie in einem
 * Protokoll und nie auf einer Kommandozeile (Faelle N18, U9).
 */
function bw_mqtt_behalten_liste(array $themen)
{
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $p = bw_paths();
    if ($p['lbhome'] === '') { return $aus; }
    $d = @json_decode((string) @file_get_contents(
             $p['lbhome'] . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return $aus; }
    $m = $d['Mqtt'];
    $hol = function ($k) use ($m) {
        return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : '';
    };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser');
    $kennwort = $hol('Brokerpass');

    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0;
        $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('bwrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $sub = pack('n', 1);
            foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
            @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $bestaetigt = false;
            $abgelehnt = false;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Je Filter ein Rueckgabebyte hinter der Paketkennung;
                       0x80 heisst abgelehnt. */
                    $rc = (string) substr($pk[1], 2);
                    if (strlen($rc) !== count($soll)) { $abgelehnt = true; }
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt = true;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    $ende = min($ende, microtime(true) + 1.0);
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    // Am empfangenen Paket: nur mit gesetztem Retain-Merkmal.
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = true;
                        if (count($aus['belegt']) === count($soll)) { break; }
                    }
                }
            }
            if ($bestaetigt && !$abgelehnt) {
                $aus['lage'] = 'ok';
            } else {
                $aus['belegt'] = array();
            }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Altwerte muessen in diesem Vollversand noch abgeraeumt werden?
 *
 * Rueckgabe array('lage' => 'erledigt'|'belegt'|'unbekannt',
 *                 'themen' => array(<thema ohne praefix>, ...)).
 *
 * Je Vollversand, bis der Merker liegt: den Broker nach allen Themen aus
 * bw_mqtt_altlast_liste() fragen (bw_mqtt_behalten_liste()); keines belegt ->
 * Merker schreiben, nichts abraeumen ('erledigt'); einige belegt -> genau
 * diese ('belegt'), kein Merker, der naechste Vollversand fragt wieder; nicht
 * zu fragen -> alle ('unbekannt'), KEIN Merker - dann raeumt jeder
 * Vollversand ab. Der Merker entsteht NUR aus der Antwort des Brokers, nie
 * aus dem Senden (Regeln/07 Nachtrag 19.09.2026; Faelle R8, N5, N10). Bis
 * 0.9.20 zaehlte er drei gesendete Runden - "dreimal gesendet" ist nicht
 * "geloescht".
 *
 * Der Merker traegt die Kennung "leer-bestaetigt <praefix>: <Themenliste>" in
 * einer eigenen Datei: ein anderes Praefix oder eine andere Liste gilt nicht
 * (Fall N15), und die Merker der 0.9.19 (retain_status_ok_geloescht) und der
 * 0.9.20 (retain_altlast) haben einen anderen Ort und gelten deshalb
 * ebenfalls nicht (Fall N14). purge_installation raeumt ihn bei jedem Update
 * mit ab; dann wird einmal nachgefragt. Bauart ko_mqtt_altlast() (KODI-NG
 * 1.2.10), dort aus tb_mqtt_altlast() (Spotpreis-Tibber 0.9.19).
 */
function bw_mqtt_altlast($praefix)
{
    $praefix = (string) $praefix;
    $liste = bw_mqtt_altlast_liste();
    $p = bw_paths();
    $merker = $p['datadir'] . '/retain_altlast_bestaetigt';
    $kennung = 'leer-bestaetigt ' . $praefix . ': ' . implode(' ', $liste);
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return array('lage' => 'erledigt', 'themen' => array());
    }
    $voll = array();
    foreach ($liste as $t) { $voll[] = $praefix . '/' . $t; }
    $f = bw_mqtt_behalten_liste($voll);
    if ($f['lage'] === 'ok' && !$f['belegt']) {
        if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
        if (@file_put_contents($merker, $kennung . "\n") !== false) {
            bw_log('MQTT: unter ' . $praefix . '/ steht keiner der frueher zurueckbehaltenen Werte '
                . 'mehr im Broker (' . implode(', ', $liste) . '; vom Broker bestaetigt).');
        }
        return array('lage' => 'erledigt', 'themen' => array());
    }
    if ($f['lage'] === 'ok') {
        $l = strlen($praefix) + 1;
        $t = array();
        foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, $l); }
        bw_log_wenn_neu('altlast_belegt', 'MQTT: im Broker stehen noch zurueckbehaltene Altwerte unter '
            . $praefix . '/ (' . implode(', ', $t) . ') - sie gehen mit leerer Nutzlast unmittelbar '
            . 'vor dem gueltigen Wert hinaus; der naechste Vollversand fragt wieder nach.');
        return array('lage' => 'belegt', 'themen' => $t);
    }
    bw_log_wenn_neu('altlast_unbekannt', 'MQTT: der Broker liess sich nicht befragen (Brokerhost, '
        . 'Brokerport und Zugangsdaten in general.json) - die frueher zurueckbehaltenen Werte unter '
        . $praefix . '/ (' . implode(', ', $liste) . ') gehen deshalb in jedem Vollversand mit leerer '
        . 'Nutzlast unmittelbar vor dem gueltigen Wert hinaus. Siehe README.', 86400);
    return array('lage' => 'unbekannt', 'themen' => $liste);
}

/** NUR die drei Lebenszeichen - fuer einen Lauf, der sonst nichts zu sagen hat. */
function bw_mqtt_lebenszeichen(?array $c = null)
{
    if ($c === null) { $c = bw_config(); }
    if (empty($c['mqtt_ein'])) { return 0; }
    $gw = bw_mqtt_gateway_info();
    if ($gw === null || $gw['udpport'] === 0) { return 0; }
    $w = bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '');
    /* Kein Abraeumen und keine Rueckfrage beim Broker hier: dieser Lauf
       schickt ok, fehler, code und fenster
       nicht mit, und eine leere Nachricht ohne den gueltigen Wert dahinter
       kaeme am Miniserver als leerer Wert an (Regeln/07). Abgeraeumt wird im
       Vollversand, bw_mqtt_publish(). */
    $lauf = bw_lauf_lesen();
    /* Dieselbe Tabelle wie oben - seit 0.9.19 geht status/ok auch hier
       fluechtig hinaus. Wer die Entscheidung am AUFRUF traefe, haette sie
       hier anders treffen koennen als vier Zeilen weiter oben. */
    return bw_mqtt_senden($gw['udpport'], array(
        bw_mqtt_zeile($w, 'status/ts', (int) $lauf['ts']),
        bw_mqtt_zeile($w, 'status/zaehler', (int) $lauf['zaehler']),
        bw_mqtt_zeile($w, 'status/ok', (int) $lauf['ok']),
    ));
}

/**
 * Die Themen, die die Deinstallation leert: jedes, das eine veroeffentlichte
 * Fassung je retained gesendet hat - die Tabelle bw_mqtt_retain() und die
 * Altwerte. Was nie retained ging (zaehler, status/ts, status/zaehler),
 * bleibt unberuehrt: eine leere Nachricht darauf loeschte nichts, kaeme aber
 * am Miniserver als leerer Wert an.
 */
function bw_mqtt_leer_themen()
{
    $t = array();
    foreach (array_keys(bw_felder()) as $k) {
        $k = strtolower($k);
        if (bw_mqtt_retain($k)) {
            $t[] = $k;
        }
    }
    foreach (bw_mqtt_altlast_liste() as $k) {
        if (!in_array($k, $t, true)) {
            $t[] = $k;
        }
    }
    return $t;
}

/**
 * Die zurueckbehaltenen Themen leeren - fuer uninstall/uninstall
 * (bw_lauf.php --mqtt-leeren). Schreibt kein Protokoll und legt nichts an.
 *
 * Geloescht wird ueber den UDP-Eingang des Gateways, "retain <thema> " mit
 * leerer Nutzlast. VOR der ersten Runde und nach jeder wird der Broker
 * gefragt (bw_mqtt_behalten_liste()); hinaus geht nur, was dort noch steht,
 * hoechstens $runden Runden. Steht nichts da, geht nichts hinaus. Ist der
 * Broker nicht zu fragen, gehen alle Themen in jeder Runde hinaus, und die
 * Ausgabe sagt, dass nicht nachgelesen wurde - der Eingang verwirft unter
 * Last Datagramme (Regeln/07), ein blosses Senden ist kein Beleg. Bis 0.9.20
 * gingen alle Themen dreimal blind hinaus (Faelle U1 bis U8). Bauart
 * ko_mqtt_leeren() (KODI-NG 1.2.10), dort aus tb_mqtt_leeren()
 * (Spotpreis-Tibber 0.9.19).
 *
 * Rueckgabe 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas bzw.
 * der Eingang war nicht erreichbar, 2 nicht moeglich.
 */
function bw_mqtt_leeren($runden = 3, $pause_us = 1000000)
{
    $c = bw_config(false);
    $praefixe = array(bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : ''));
    /* AUCH DAS ZULETZT BENUTZTE ALTE PRAEFIX (M2, Durchgang 30.09.2026). Bis
       0.9.21 leerte die Deinstallation nur das aktuelle - nach einem
       Praefixwechsel blieben die fuenf Zustaende des alten fuer immer im
       Broker (gemessen, Pruefbericht code M2). */
    $alt = bw_mqtt_alter_praefix();
    if ($alt !== '' && !in_array($alt, $praefixe, true)) {
        $praefixe[] = $alt;
    }
    $rc = 0;
    foreach ($praefixe as $w) {
        $e = bw_mqtt_raeumen($w, $runden, $pause_us);
        foreach ($e['zeilen'] as $z) {
            echo $z . "\n";
        }
        $rc = max($rc, (int) $e['rc']);
    }
    return $rc;
}

/**
 * Die zurueckbehaltenen Themen EINES Praefixes leeren - der Weg, den
 * bw_mqtt_leeren() bis 0.9.21 fuer das aktuelle Praefix ging, unveraendert
 * herausgezogen (Runden, Pausen, Nachfrage beim Broker vor der ersten Runde
 * und nach jeder), damit ihn auch die Oberflaeche beim Praefixwechsel und
 * beim Abschalten geht (M2). Statt auszugeben sammelt er die Zeilen; wer ruft,
 * entscheidet, wohin sie gehen.
 *
 * Rueckgabe array('rc' => 0 geleert oder nicht nachpruefbar | 1 es steht noch
 * etwas bzw. der Eingang war nicht erreichbar | 2 kein Eingangsport,
 * 'zeilen' => Protokollzeilen mit <OK>/<INFO>/<WARNING>, 'n' => Zahl der
 * Themen, 'offen' => was nach dem Nachlesen noch steht, 'nachgelesen' =>
 * hat der Broker geantwortet, 'eingang' => war der UDP-Eingang da).
 */
function bw_mqtt_raeumen($w, $runden = 3, $pause_us = 1000000)
{
    $aus = array('rc' => 0, 'zeilen' => array(), 'n' => 0, 'offen' => array(),
                 'nachgelesen' => false, 'eingang' => true);
    $gw = bw_mqtt_gateway_info();
    if ($gw === null || $gw['udpport'] === 0) {
        $aus['zeilen'][] = '<INFO> MQTT: in der general.json steht kein UDP-Eingangsport des Gateways - '
           . 'zurueckbehaltene Themen unter ' . $w . '/ wurden nicht geleert.';
        $aus['rc'] = 2;
        $aus['eingang'] = false;
        return $aus;
    }
    $alle = array();
    foreach (bw_mqtt_leer_themen() as $t) { $alle[] = $w . '/' . $t; }
    $n = count($alle);
    $aus['n'] = $n;
    $f = bw_mqtt_behalten_liste($alle);
    $nachgelesen = ($f['lage'] === 'ok');
    $offen = $nachgelesen ? array_keys($f['belegt']) : $alle;
    if ($nachgelesen && !$offen) {
        $aus['zeilen'][] = '<OK> MQTT: der Broker bestaetigt: keines der ' . $n . ' Themen unter ' . $w
           . '/ steht zurueckbehalten - nichts zu leeren.';
        $aus['nachgelesen'] = true;
        return $aus;
    }
    $eno = 0;
    $etxt = '';
    $fp = @stream_socket_client('udp://127.0.0.1:' . (int) $gw['udpport'], $eno, $etxt, 2);
    if (!$fp) {
        $aus['zeilen'][] = '<WARNING> MQTT: der UDP-Eingang des Gateways ist nicht erreichbar (Port '
           . (int) $gw['udpport'] . ') - zurueckbehaltene Themen unter ' . $w
           . '/ wurden nicht geleert.';
        $aus['rc'] = 1;
        $aus['eingang'] = false;
        $aus['offen'] = $offen;
        $aus['nachgelesen'] = $nachgelesen;
        return $aus;
    }
    $zu_leeren = count($offen);
    $datagramme = 0;
    $gelaufen = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep((int) $pause_us); }
        $gelaufen = $r;
        foreach ($offen as $t) {
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest.
            if (@fwrite($fp, 'retain ' . $t . ' ') !== false) { $datagramme++; }
        }
        usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
        $f = bw_mqtt_behalten_liste($offen);
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_keys($f['belegt']);
        } else {
            $nachgelesen = false;
        }
    }
    fclose($fp);
    $aus['offen'] = $offen;
    $aus['nachgelesen'] = $nachgelesen;
    $aus['zeilen'][] = '<INFO> MQTT: ' . $zu_leeren . ' von ' . $n . ' Themen unter ' . $w . '/ mit leerer Nutzlast '
       . 'an den UDP-Eingang ' . (int) $gw['udpport'] . ' des Gateways gesendet (' . $gelaufen
       . ' Runde(n), ' . $datagramme . ' Datagramme).';
    if ($nachgelesen && !$offen) {
        $aus['zeilen'][] = '<OK> MQTT: der Broker bestaetigt: keines der ' . $n . ' Themen steht mehr '
           . 'zurueckbehalten.';
        return $aus;
    }
    if ($nachgelesen) {
        $aus['zeilen'][] = '<WARNING> MQTT: ' . count($offen) . ' Themen stehen noch zurueckbehalten im Broker ('
           . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
           . '). Von Hand: mosquitto_pub -r -n -t <thema> (mit den Broker-Zugangsdaten).';
        $aus['rc'] = 1;
        return $aus;
    }
    $aus['zeilen'][] = '<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang '
       . 'verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit '
       . 'mosquitto_pub -r -n -t <thema> von Hand loeschen.';
    return $aus;
}

/** Das zuletzt benutzte alte Praefix (M2) - '' wenn keines gemerkt oder unzulaessig. */
function bw_mqtt_alter_praefix()
{
    $f = bw_paths()['praefix_alt'];
    if (!is_file($f)) { return ''; }
    $s = trim((string) @file_get_contents($f));
    return bw_wert_pruefen('mqtt_thema', $s) ? bw_mqtt_praefix($s) : '';
}

/** Ein gewechseltes Praefix merken, damit die Deinstallation es mitnimmt (M2). */
function bw_mqtt_alter_praefix_merken($praefix)
{
    $s = bw_mqtt_praefix($praefix);
    if (!bw_wert_pruefen('mqtt_thema', $s)) { return false; }
    return bw_text_schreiben(bw_paths()['praefix_alt'], $s . "\n", 0644);
}

/**
 * Was nach einer Aenderung von MQTT-Schalter oder Praefix zu tun ist - aus dem
 * Reiter MQTT und nach dem Zurueckspielen einer Sicherung (M2, M4, Durchgang
 * 30.09.2026).
 *
 * War MQTT an und wechselt das Praefix oder wird MQTT abgeschaltet, werden
 * die zurueckbehaltenen Themen des BISHERIGEN Praefixes abgeraeumt und ueber
 * den Broker nachgelesen (bw_mqtt_raeumen()). Bis 0.9.21 blieben nach einem
 * Praefixwechsel fuenf Zustaende im Broker, und nach dem Abschalten alle; nach
 * jedem Neustart von Broker oder Gateway kamen sie wieder in Loxone an
 * (gemessen, Pruefbericht code M2). Ein gewechseltes Praefix wird gemerkt,
 * damit die Deinstallation es mitnimmt. Die Abodatei folgt dem neuen Praefix.
 *
 * Rueckgabe array('ok' => Meldungen, 'fehler' => Meldungen) fuer die
 * Oberflaeche - uebersetzt, eingesetzte Werte maskiert. Die Protokollzeilen
 * des Abraeumens gehen ins Protokoll.
 */
function bw_mqtt_nach_aenderung(array $alt, array $neu)
{
    $aus = array('ok' => array(), 'fehler' => array());
    $pa = bw_mqtt_praefix(isset($alt['mqtt_thema']) ? $alt['mqtt_thema'] : '');
    $pn = bw_mqtt_praefix(isset($neu['mqtt_thema']) ? $neu['mqtt_thema'] : '');
    if ($pa !== $pn) {
        bw_mqtt_alter_praefix_merken($pa);
    }
    if (!empty($alt['mqtt_ein']) && ($pa !== $pn || empty($neu['mqtt_ein']))) {
        $e = bw_mqtt_raeumen($pa, 3, 1000000);
        foreach ($e['zeilen'] as $z) {
            bw_log('Abraeumen nach Aenderung: ' . $z);
        }
        if (!$e['eingang']) {
            $aus['fehler'][] = sprintf(bw_t('MQTT.RAEUMEN_KEIN_PORT'), bw_e($pa));
        } elseif ($e['nachgelesen'] && !$e['offen']) {
            $aus['ok'][] = sprintf(bw_t('MQTT.RAEUMEN_OK'), bw_e($pa));
        } elseif ($e['nachgelesen']) {
            $aus['fehler'][] = sprintf(bw_t('MQTT.RAEUMEN_OFFEN'), count($e['offen']), bw_e($pa),
                bw_e(implode(', ', array_slice($e['offen'], 0, 5))));
        } else {
            $aus['fehler'][] = sprintf(bw_t('MQTT.RAEUMEN_UNGEPRUEFT'), bw_e($pa));
        }
    }
    bw_abo_datei($pn, true);
    return $aus;
}

/**
 * Die Abodatei des MQTT-Gateways: config/plugins/<ordner>/mqtt_subscriptions.cfg
 * mit einer Zeile "<praefix>/#" (M4, Durchgang 30.09.2026).
 *
 * Das Gateway V1 liest diese Datei jedes installierten Plugins und abonniert
 * daraus, beim Start und bei jeder Aenderung (Regeln/07, am Geraet belegt
 * 13.09.2026, Midea2Lox). Bis 0.9.21 lieferte dieses Plugin keine; unter V1
 * kam ohne einen Eintrag von Hand am Miniserver nichts an, und nach einem
 * Praefixwechsel zeigte der Eintrag ins Leere. Mitgeliefert wird
 * "beschattung/#"; beim Speichern, nach dem Zurueckspielen und in jedem Lauf
 * wird sie nachgefuehrt, NUR wenn sie abweicht (Bauart Einspeisebremse
 * 0.9.20, BatterieBMS 0.9.30). Ob Gateway V2 die Datei liest, ist NICHT
 * gemessen. Geschrieben wird nur in einer Anlage, nie in ein Archiv.
 *
 * Rueckgabe array(pfad, steht das Praefix darin?).
 */
function bw_abo_datei($praefix, $schreiben = false)
{
    $p = bw_paths();
    $pfad = $p['config'] . '/mqtt_subscriptions.cfg';
    $soll = bw_mqtt_praefix($praefix) . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && $p['lbhome'] !== '' && $roh !== $soll . "\n" && is_dir($p['config'])) {
        if (bw_text_schreiben($pfad, $soll . "\n", 0644)) {
            bw_log('MQTT: Abodatei des Gateways gesetzt: ' . $soll . ' (' . $pfad . ').');
            $da = true;
        } else {
            bw_log_wenn_neu('abo_datei', 'MQTT: die Abodatei ' . $pfad . ' liess sich nicht '
                . 'schreiben - unter Gateway V1 muss das Abo ' . $soll . ' von Hand eingetragen werden.');
        }
    }
    return array($pfad, $da);
}

/** Die Themen, die dieses Plugin veroeffentlicht - fuer die Tabelle im Reiter. */
function bw_mqtt_themen(?array $c = null)
{
    if ($c === null) { $c = bw_config(); }
    $w = bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '');
    $aus = array();
    foreach (bw_felder() as $k => $i) {
        /* Dieselbe Liste wie beim Senden (bw_nur_http()) - seit 0.9.22 auch
           LAUFALTER. */
        if (in_array($k, bw_nur_http(), true)) { continue; }
        $aus[$w . '/' . strtolower($k)] = $i['bez'];
    }
    $aus[$w . '/status/ts'] = 'FELD.TS';
    $aus[$w . '/status/zaehler'] = 'FELD.ZAEHLER';
    /* Dasselbe Feld wie OK, deshalb DIESELBE Beschreibung. Bis 0.9.12 stand
       hier FELD.LEBT ("1 bei jedem Durchgang") - zwei Texte fuer einen Wert,
       und der zweite war der falsche. */
    $aus[$w . '/status/ok'] = 'FELD.OK';
    return $aus;
}


/* ==================================================================
 * D. DAS MERKWORT DES ENDPUNKTS
 * ==================================================================
 *
 * Ein Aufruf, der etwas AUSLOEST, verlangt ein Merkwort. Abfragende Aufrufe
 * blieben offen - sie aendern nichts -, aber dieser Endpunkt kann senden,
 * und deshalb ist er ganz geschuetzt.
 *
 * Unterschieden wird nicht an "Datei da oder nicht", sondern daran, ob der
 * SCHLUESSEL schon einmal geschrieben wurde:
 *     Schluessel fehlt      noch nie gesetzt      -> erzeugen
 *     Schluessel da, leer   bewusst geleert       -> in Ruhe lassen
 * Fuer empty() sehen beide gleich aus, und genau darin liegt der Unterschied
 * zwischen "neu" und "abgeschaltet". Ein Merkwort, das nachwaechst, laesst
 * sich nicht abschalten.
 * ================================================================== */
function bw_token_neu()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }
    return substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 32);
}

/**
 * Das Merkwort lesen - und beim ERSTEN Mal anlegen.
 *
 * $erzeugen = false liest nur. Der unangemeldete Endpunkt ruft so auf: wer
 * sich nicht ausweisen kann, hinterlaesst keine Datei, auch keine harmlose.
 */
function bw_token($erzeugen = false)
{
    $c = bw_config($erzeugen);
    $ist = isset($c['aktionstoken']) ? trim((string) $c['aktionstoken']) : '';
    if ($ist !== '' || !$erzeugen) {
        return $ist;
    }
    $lage = bw_config_lage();
    if (!in_array('aktionstoken', $lage['fehlend'], true)) {
        return '';          /* bewusst geleert - nicht nachwachsen lassen */
    }
    $c['aktionstoken'] = bw_token_neu();
    if (!bw_config_speichern($c)) {
        return '';
    }
    bw_log('Ein Merkwort fuer den Endpunkt wurde erzeugt.');
    return $c['aktionstoken'];
}

/**
 * Die Adresse des Endpunkts - an EINER Stelle gebildet.
 *
 * $roh laesst die Platzhalter von Loxone unangetastet: ein <v> muss <v>
 * bleiben. Als %3Cv%3E ginge der Befehl hinaus und taete nichts.
 */
function bw_endpunkt_pfad(array $werte, $roh = false)
{
    $teile = array();
    foreach ($werte as $k => $v) {
        $teile[] = rawurlencode((string) $k) . '='
                 . ($roh ? (string) $v : rawurlencode((string) $v));
    }
    return '/plugins/' . bw_paths()['plugin'] . '/index.php?' . implode('&', $teile);
}


/* ==================================================================
 * E. DIE WIRKUNG MESSEN - NICHT DEN RUECKGABEWERT
 * ==================================================================
 *
 * Bis 0.9.10 meldete dieses Plugin Erfolg, wenn der Miniserver HTTP 200
 * sagte. Das ist der Rueckgabewert. Ob danach eine Automatik scharf ist,
 * wusste niemand.
 *
 * Messbar ist es: jeder Baustein vom Typ Jalousie fuehrt einen Zustand
 * autoActive, und der Miniserver liefert dazu sogar einen Klartext
 * (autoInfoText). Am 28.08.2026 an einer Anlage mit 25 Jalousien gemessen:
 * 13 mit autoActive = 1, 12 mit 0.
 *
 * ZWEI GRENZEN, und beide gehoeren hierher:
 *
 * 1. Der ZENTRALBAUSTEIN fuehrt kein autoActive. Gemessen an einer
 *    CentralJalousie: nur events, jLocked und safetyActive. Wer die Wirkung
 *    messen will, liest die Jalousien - nicht den Baustein, an den er sendet.
 *
 * 2. Dass 'jdev/sps/io/{uuid}/state' ein gueltiger Befehl ist, steht in
 *    KEINEM der beiden Loxone-Dokumente. Belegt ist nur
 *    'jdev/sps/io/{uuid}/{befehl}'. Dieselbe Ehrlichkeit steht im
 *    Dashboard-Plugin dieses Hauses, das denselben Weg als Notnagel benutzt.
 *    Deshalb: ab Werk AUS, und im Reiter Test ein Knopf, der es EINMAL an
 *    der eigenen Anlage misst. Gemessen ist besser als vermutet.
 * ================================================================== */

/** Die Strukturdatei holen. Rueckgabe: array(ok, Meldung, controls). */
function bw_struktur_holen(array $c)
{
    /* EINMAL JE PROZESS (b1, Verbesserungsbau 30.09.2026): der Takt liest vor
       einem Befehl die Positionen und zaehlt danach die Automatiken - beides
       aus derselben Strukturdatei. Gemerkt wird nur ein Erfolg, je Adresse
       und Benutzer; jede Anfrage an Oberflaeche oder Endpunkt ist ein eigener
       Prozess und holt neu. */
    static $bw_merk = array();
    $m = bw_miniserver_gewaehlt($c);
    if ($m === null) {
        return array(0, bw_t('TEXT.KEIN_MS'), array());
    }
    $url = 'http://' . $m['adresse'] . ':' . $m['port'] . '/data/LoxAPP3.json';
    $bw_mk = $url . '|' . $m['user'];
    if (isset($bw_merk[$bw_mk])) {
        return $bw_merk[$bw_mk];
    }
    $kopf = array('Accept: application/json');
    if ($m['user'] !== '') {
        /* Die Zugangsdaten gehen in den KOPF, nicht in die Adresse: eine
           Adresse landet im Protokoll des Webservers, ein Kopf nicht. */
        $kopf[] = 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']);
    }
    list($code, $roh, $fehler) = bw_holen($url, $kopf, max(10, (int) $c['timeout'] * 3));
    if ($code === 0) {
        return array(0, sprintf(bw_t('TEXT.STRUKTUR_STUMM'), bw_e($m['adresse']),
                                bw_e($fehler)), array());
    }
    if ($code === 401) {
        return array(0, bw_t('TEXT.STRUKTUR_401'), array());
    }
    if ($code !== 200) {
        return array(0, sprintf(bw_t('TEXT.STRUKTUR_HTTP'), $code), array());
    }
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['controls']) || !is_array($d['controls'])) {
        return array(0, bw_t('TEXT.STRUKTUR_FORM'), array());
    }
    $bw_merk[$bw_mk] = array(1, '', $d);
    return $bw_merk[$bw_mk];
}

/**
 * Die Automatiken zaehlen: wie viele Jalousien sind scharf?
 *
 * Rueckgabe: array(ok, Meldung, array(scharf, gesamt, zeilen)).
 * 'zeilen' traegt je Baustein Name, Raum, Zustand und den Klartext des
 * Miniservers - dieser Klartext ist die eigentliche Auskunft.
 */
function bw_automatiken(array $c, $hoechstens = 40)
{
    list($ok, $meldung, $d) = bw_struktur_holen($c);
    if (!$ok) {
        return array(0, $meldung, array('scharf' => -1, 'gesamt' => -1, 'zeilen' => array()));
    }
    $raeume = array();
    foreach ((array) (isset($d['rooms']) ? $d['rooms'] : array()) as $u => $r) {
        $raeume[$u] = is_array($r) && isset($r['name']) ? (string) $r['name'] : '';
    }
    $m = bw_miniserver_gewaehlt($c);
    if ($m === null) {
        /* Kann hier nur eintreten, wenn die Auswahl zwischen dem Holen der
           Struktur und dieser Zeile weggefallen ist - abgefangen wird es
           trotzdem, weil ein Zugriff auf null unter 8.x toedlich ist. */
        return array(0, bw_t('TEXT.KEIN_MS'), array());
    }
    $kopf = array('Accept: application/json');
    if ($m['user'] !== '') {
        $kopf[] = 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']);
    }
    $zeilen = array();
    $scharf = 0;
    $gesamt = 0;
    foreach ($d['controls'] as $uuid => $b) {
        if (!is_array($b) || (string) (isset($b['type']) ? $b['type'] : '') !== 'Jalousie') {
            continue;
        }
        $z = isset($b['states']) && is_array($b['states']) ? $b['states'] : array();
        if (!isset($z['autoActive'])) {
            continue;      /* kein Beschattungsbaustein - zaehlt nicht mit */
        }
        if ($gesamt >= $hoechstens) { break; }
        $gesamt++;
        $wert = bw_zustand_lesen($m, $kopf, (string) $z['autoActive'], (int) $c['timeout']);
        $erlaubt = isset($z['autoAllowed'])
            ? bw_zustand_lesen($m, $kopf, (string) $z['autoAllowed'], (int) $c['timeout']) : null;
        $grund = isset($z['autoInfoText'])
            ? bw_zustand_lesen($m, $kopf, (string) $z['autoInfoText'], (int) $c['timeout']) : null;
        if ($wert === '1' || $wert === '1.0' || (is_numeric($wert) && (float) $wert >= 0.5)) {
            $scharf++;
            $an = 1;
        } else {
            $an = ($wert === null) ? -1 : 0;
        }
        $zeilen[] = array(
            'name'  => (string) (isset($b['name']) ? $b['name'] : $uuid),
            /* is_scalar() ZUERST. Ist 'room' in der Antwort der Anlage kein
               Skalar, ist isset($raeume[$b['room']]) unter 7.4 eine Warnung
               und unter 8.x ein ungefangener TypeError - am Endpunkt also
               HTTP 500 mit leerem Rumpf, und der Miniserver bekaeme gar
               nichts zu lesen. */
            'raum'  => (isset($b['room']) && is_scalar($b['room'])
                        && isset($raeume[(string) $b['room']]))
                       ? $raeume[(string) $b['room']] : '',
            'an'    => $an,
            'darf'  => ($erlaubt === null) ? -1 : ((float) $erlaubt >= 0.5 ? 1 : 0),
            'grund' => (string) $grund,
        );
    }
    if ($gesamt === 0) {
        /* Eine Zeile, die ueber eine LEERE Menge urteilt, sagt nichts.
           "0 von 0 scharf" ist kein Haken. */
        return array(0, bw_t('TEXT.KEINE_JALOUSIEN'),
                     array('scharf' => -1, 'gesamt' => -1, 'zeilen' => array()));
    }
    return array(1, '', array('scharf' => $scharf, 'gesamt' => $gesamt, 'zeilen' => $zeilen));
}

/** Einen einzelnen Zustand ueber HTTP holen. Rueckgabe: Wert als Text oder null. */
function bw_zustand_lesen(array $m, array $kopf, $uuid, $frist)
{
    $uuid = trim((string) $uuid);
    if ($uuid === '') { return null; }
    $url = 'http://' . $m['adresse'] . ':' . $m['port'] . '/jdev/sps/io/'
         . rawurlencode($uuid) . '/state';
    list($code, $roh, $fehler) = bw_holen($url, $kopf, max(2, min(30, (int) $frist)));
    if ($code !== 200 || $roh === null) { return null; }
    $d = json_decode((string) $roh, true);
    if (is_array($d) && isset($d['LL']['value'])) {
        return (string) $d['LL']['value'];
    }
    return null;
}


/* ==================================================================
 * E2. DIE WIRKUNG AN DER POSITION (b1, Verbesserungsbau 30.09.2026)
 * ==================================================================
 *
 * Die Kachel "zuletzt gesendet" sagte bis 0.9.23 nur, WANN gesendet wurde.
 * Ob danach ein Rollladen gefahren ist, stand nirgends - die Zaehlung liest
 * autoActive, und das sagt "die Automatik darf", nicht "es hat sich etwas
 * bewegt".
 *
 * Gemessen wird der Zustand 'position' jeder Jalousie, belegt in der
 * Strukturdatei der Anlage (Geraet/2026-09-08/LoxAPP3.json: 25 Jalousien,
 * jede fuehrt position, shadePosition, autoActive, autoAllowed ...). Vor dem
 * Befehl einmal, danach im Takt alle fuenf Minuten, bis sich eine Position um
 * mindestens 0,01 geaendert hat oder bw_wirkung_frist() vorbei ist.
 *
 * NUR MIT EINGESCHALTETER WIRKUNGSMESSUNG (pruefen_ein, ab Werk aus) - sie ist
 * die Zustimmung, Zustaende beim Miniserver abzufragen. Hoechstens 40
 * Jalousien wie bei der Zaehlung; der erste Zustand, der sich nicht lesen
 * laesst, beendet die Runde (ein toter Miniserver kostet sonst 40 Fristen in
 * einem Fuenfminutentakt).
 *
 * WAS "FESTGESTELLT" HEISST: der Zeitpunkt der Nachmessung, die die Aenderung
 * zuerst sah - Raster fuenf Minuten, nicht der Augenblick der Bewegung. Und
 * "danach geaendert" heisst nicht "vom Befehl bewegt": auch eine Hand am
 * Taster aendert die Position. Die Kachel sagt es so.
 * ================================================================== */

/** Wie lange nach einem Befehl nachgemessen wird (Sekunden). */
function bw_wirkung_frist()
{
    return 1800;
}

/**
 * Die Positionen der Jalousien lesen.
 *
 * $stellen null: alle Jalousien mit dem Zustand position aus der
 * Strukturdatei (hoechstens 40); sonst genau diese Zustandskennungen.
 * Rueckgabe: ok, grund (Kennwort fuer die Kachel), detail (Klartext),
 * werte (Kennung => Position), namen (Kennung => Bausteinname).
 */
function bw_positionen(array $c, ?array $stellen = null)
{
    $aus = array('ok' => false, 'grund' => '', 'detail' => '', 'werte' => array(), 'namen' => array());
    if ($stellen === null) {
        list($ok, $meldung, $d) = bw_struktur_holen($c);
        if (!$ok) {
            $aus['grund'] = 'STRUKTUR';
            $aus['detail'] = html_entity_decode(strip_tags((string) $meldung), ENT_QUOTES, 'UTF-8');
            return $aus;
        }
        $stellen = array();
        foreach ($d['controls'] as $uuid => $b) {
            if (!is_array($b) || (string) (isset($b['type']) ? $b['type'] : '') !== 'Jalousie') {
                continue;
            }
            $z = (isset($b['states']) && is_array($b['states'])) ? $b['states'] : array();
            if (!isset($z['position']) || !is_scalar($z['position']) || trim((string) $z['position']) === '') {
                continue;
            }
            if (count($stellen) >= 40) {
                break;
            }
            $k = trim((string) $z['position']);
            $stellen[] = $k;
            $aus['namen'][$k] = (isset($b['name']) && is_scalar($b['name'])) ? (string) $b['name'] : (string) $uuid;
        }
        if (!$stellen) {
            $aus['grund'] = 'KEINE_JALOUSIE';
            return $aus;
        }
    }
    $m = bw_miniserver_gewaehlt($c);
    if ($m === null) {
        $aus['grund'] = 'KEIN_MS';
        return $aus;
    }
    $kopf = array('Accept: application/json');
    if ($m['user'] !== '') {
        $kopf[] = 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']);
    }
    foreach ($stellen as $k) {
        $k = (string) $k;
        $v = bw_zustand_lesen($m, $kopf, $k, (int) $c['timeout']);
        if ($v === null || !is_numeric($v)) {
            $aus['grund'] = 'ZUSTAND';
            $aus['werte'] = array();
            return $aus;
        }
        $aus['werte'][$k] = round((float) $v, 4);
    }
    $aus['ok'] = true;
    return $aus;
}

/** Die Positionen VOR einem Befehl - null, wenn die Messung aus ist oder nur geprobt wird. */
function bw_wirkung_vorher(array $c)
{
    if (empty($c['pruefen_ein']) || bw_trocken()) {
        return null;
    }
    return bw_positionen($c);
}

/**
 * Nach einem Befehl die Wirkung eroeffnen - im Stand, den bw_stand_nach_senden()
 * gerade gebildet hat. $vorher null (Messung aus): ein alter Eintrag faellt
 * weg, die Kachel sagt "nicht gemessen".
 */
function bw_wirkung_beginnen(array $stand, $vorher, array $ergebnisse)
{
    if (!is_array($vorher)) {
        unset($stand['wirkung']);
        return $stand;
    }
    $gut = 0;
    foreach ($ergebnisse as $r) {
        if (!empty($r['ok']) && empty($r['probe'])) { $gut++; }
    }
    $w = array('ts' => isset($stand['letzte']) ? (int) $stand['letzte'] : time(), 'lage' => 'offen',
               'n' => 0, 'vorher' => array(), 'namen' => array(), 'geaendert' => 0, 'welche' => array(),
               'erste' => 0, 'gemessen' => 0, 'fehlschlag' => 0, 'grund' => '', 'detail' => '');
    if ($gut === 0) {
        $w['lage'] = 'nicht_messbar';
        $w['grund'] = 'NICHT_ANGENOMMEN';
    } elseif (empty($vorher['ok'])) {
        $w['lage'] = 'nicht_messbar';
        $w['grund'] = (string) $vorher['grund'];
        $w['detail'] = (string) $vorher['detail'];
    } else {
        $w['vorher'] = $vorher['werte'];
        $w['namen'] = $vorher['namen'];
        $w['n'] = count($vorher['werte']);
    }
    $stand['wirkung'] = $w;
    return $stand;
}

/** Steht eine Nachmessung aus? */
function bw_wirkung_offen(array $c, array $stand)
{
    return !empty($c['pruefen_ein']) && isset($stand['wirkung']) && is_array($stand['wirkung'])
        && isset($stand['wirkung']['lage']) && $stand['wirkung']['lage'] === 'offen';
}

/**
 * Im Takt nachmessen. Ein Fehlschlag laesst die Messung offen (bis zur Frist);
 * die erste Aenderung schliesst sie. Nach der Frist (mit einem halben Takt
 * Toleranz wie beim Abstand) ist sie "unveraendert".
 */
function bw_wirkung_nachmessen(array $c, array $stand, $jetzt = null)
{
    if (!bw_wirkung_offen($c, $stand)) {
        return $stand;
    }
    $jetzt = ($jetzt === null) ? time() : (int) $jetzt;
    $w = $stand['wirkung'];
    $vorher = (isset($w['vorher']) && is_array($w['vorher'])) ? $w['vorher'] : array();
    $seit = $jetzt - (int) $w['ts'];
    if (!$vorher) {
        $w['lage'] = 'nicht_messbar';
        $w['grund'] = 'KEINE_JALOUSIE';
    } elseif ($seit > bw_wirkung_frist() + 150) {
        $w['lage'] = 'unveraendert';
    } else {
        $erg = bw_positionen($c, array_keys($vorher));
        if (!$erg['ok']) {
            $w['fehlschlag'] = $jetzt;
            $w['grund'] = (string) $erg['grund'];
            $w['detail'] = (string) $erg['detail'];
        } else {
            $w['gemessen'] = $jetzt;
            $w['fehlschlag'] = 0;
            $w['grund'] = '';
            $w['detail'] = '';
            $welche = array();
            foreach ($vorher as $k => $v) {
                if (isset($erg['werte'][$k]) && abs((float) $erg['werte'][$k] - (float) $v) >= 0.01) {
                    $welche[] = isset($w['namen'][$k]) ? (string) $w['namen'][$k] : (string) $k;
                }
            }
            if ($welche) {
                $w['lage'] = 'geaendert';
                $w['erste'] = $jetzt;
                $w['geaendert'] = count($welche);
                $w['welche'] = array_slice($welche, 0, 5);
            } elseif ($seit >= bw_wirkung_frist()) {
                $w['lage'] = 'unveraendert';
            }
        }
    }
    if ($w['lage'] !== 'offen') {
        /* Abgeschlossen: die Einzelwerte werden nicht mehr gebraucht. */
        $w['vorher'] = array();
        $w['namen'] = array();
    }
    $stand['wirkung'] = $w;
    return $stand;
}

/** Der Satz fuer die Kachel "zuletzt gesendet" - Klartext, maskiert wird bei der Ausgabe. */
function bw_wirkung_text(array $c, array $stand)
{
    $letzte = isset($stand['letzte']) ? (int) $stand['letzte'] : 0;
    if ($letzte <= 0) {
        return '';
    }
    $w = (isset($stand['wirkung']) && is_array($stand['wirkung'])) ? $stand['wirkung'] : null;
    if ($w === null || (int) (isset($w['ts']) ? $w['ts'] : 0) !== $letzte) {
        return bw_t(empty($c['pruefen_ein']) ? 'TEXT.WIRKUNG_AUS' : 'TEXT.WIRKUNG_KEINE');
    }
    $lage = isset($w['lage']) ? (string) $w['lage'] : '';
    $grund = function () use ($w) {
        $g = isset($w['grund']) ? (string) $w['grund'] : '';
        $t = bw_t('TEXT.WIRKUNG_G_' . ($g !== '' ? $g : 'ZUSTAND'));
        return ($g === 'STRUKTUR') ? sprintf($t, isset($w['detail']) ? (string) $w['detail'] : '') : $t;
    };
    if ($lage === 'nicht_messbar') {
        return sprintf(bw_t('TEXT.WIRKUNG_NICHT_MESSBAR'), $grund());
    }
    if ($lage === 'geaendert') {
        return sprintf(bw_t('TEXT.WIRKUNG_GEAENDERT'), (int) $w['geaendert'], (int) $w['n'],
                       bw_zeitpunkt((int) $w['erste']), (int) round(((int) $w['erste'] - $letzte) / 60),
                       implode(', ', (array) $w['welche']) . ((int) $w['geaendert'] > 5 ? ', ...' : ''));
    }
    if ($lage === 'unveraendert') {
        return sprintf(bw_t('TEXT.WIRKUNG_UNVERAENDERT'), (int) round(bw_wirkung_frist() / 60), (int) $w['n']);
    }
    if (empty($c['pruefen_ein'])) {
        return bw_t('TEXT.WIRKUNG_AUS');
    }
    $bis = bw_zeitpunkt($letzte + bw_wirkung_frist());
    if (!empty($w['fehlschlag']) && (int) $w['fehlschlag'] >= (int) $w['gemessen']) {
        return sprintf(bw_t('TEXT.WIRKUNG_FEHLSCHLAG'), bw_zeitpunkt((int) $w['fehlschlag']), $grund(), $bis);
    }
    if (!empty($w['gemessen'])) {
        return sprintf(bw_t('TEXT.WIRKUNG_BISHER'), (int) $w['n'], bw_zeitpunkt((int) $w['gemessen']), $bis);
    }
    return sprintf(bw_t('TEXT.WIRKUNG_OFFEN'), (int) $w['n'], $bis);
}

/**
 * Ein HTTP-Abruf mit einer Zeitschranke, die auch den VERBINDUNGSAUFBAU deckt.
 *
 * Rueckgabe: array(HTTP-Code, Rumpf|null, Fehlertext).
 */
function bw_holen($url, array $kopf, $frist)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $kopf,
            CURLOPT_CONNECTTIMEOUT => $frist,
            CURLOPT_TIMEOUT        => $frist,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'LoxBerry Beschattungswaechter',
        ));
        $a = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $f = curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        return array($code, $a === false ? null : $a, $f);
    }
    list($code, $a) = bw_http_ohne_curl($url, $kopf, $frist);
    return array($code, $a === false ? null : $a, $code === 0 ? 'keine Antwort' : '');
}

/**
 * Ein HTTP-Abruf OHNE curl - der gemeinsame Weg von bw_senden() und
 * bw_holen() (C4, Durchgang 30.09.2026).
 *
 * Bis 0.9.21 lasen beide die Statuszeilen aus der Variablen, die PHP nach
 * file_get_contents() im Aufrufer anlegt. PHP 8.5 meldet schon beim
 * UEBERSETZEN der Bibliothek, dass sie verfaellt - also bei jedem Laden,
 * auch mit curl, auch aus uninstall/uninstall (bw_lauf.php --mqtt-leeren
 * mit 2>&1), und damit im Installationsprotokoll. Unter PHP 9 fiele der Weg
 * ganz aus. Eine Weiche nach der Fassung genuegt nicht: schon die Nennung
 * der Variablen loest die Meldung aus.
 *
 * Jetzt: fopen() mit demselben Kontext, die Kopfzeilen aus
 * stream_get_meta_data()['wrapper_data'] - das traegt von 7.4 bis 8.5.
 * Zeitschranke, keine Weiterleitung (follow_location 0) und ignore_errors
 * wie bisher; bei einer Weiterleitung stehen mehrere Statuszeilen darin, es
 * gilt die letzte.
 *
 * Rueckgabe: array(HTTP-Code oder 0, Rumpf oder false).
 */
function bw_http_ohne_curl($url, array $kopf, $frist)
{
    $vorher = ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', (string) $frist);
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => implode("\r\n", $kopf) . "\r\n",
        'timeout' => $frist, 'ignore_errors' => true,
        'follow_location' => 0, 'max_redirects' => 1,
        'user_agent' => 'LoxBerry Beschattungswaechter')));
    $code = 0;
    $rumpf = false;
    $fh = @fopen($url, 'r', false, $ctx);
    if ($fh !== false) {
        $meta = stream_get_meta_data($fh);
        $zeilen = (isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
            ? $meta['wrapper_data'] : array();
        foreach ($zeilen as $z) {
            if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $t)) {
                $code = (int) $t[1];
            }
        }
        $rumpf = stream_get_contents($fh);
        fclose($fh);
    }
    @ini_set('default_socket_timeout', (string) $vorher);
    return array($code, $rumpf);
}


/* ==================================================================
 * F. DER TROCKENLAUF
 * ==================================================================
 *
 * Ein Schalter, den die SENDEFUNKTION abfragt - keine zweite Funktion, die
 * den Vorgang beschreibt. Zwei Stellen, die dasselbe erzeugen, laufen
 * auseinander, und dann zeigt die Vorschau etwas anderes an, als der
 * Ernstfall tut.
 *
 * Er wird in einem finally zurueckgesetzt: bliebe er stehen, taete der
 * naechste echte Befehl im selben Prozess still nichts.
 * ================================================================== */
function bw_trocken($setzen = null)
{
    static $an = false;
    if ($setzen !== null) { $an = (bool) $setzen; }
    return $an;
}


/* ==================================================================
 * G. DER BEFUND - EINE QUELLE FUER OBERFLAECHE, HEALTHCHECK UND MELDUNG
 * ==================================================================
 *
 * Drei Stellen, die dasselbe anders sagen, sind zwei zu viel. Die Reihenfolge
 * ist die der URSACHEN, nicht die der Wirkungen: wer bei "seit Stunden nichts
 * gesendet" anfaengt, waehrend gar keine Kennung eingetragen ist, schickt den
 * Leser in die falsche Ecke.
 *
 * Die Skala ist die von LoxBerry: 3 Fehler, 4 Warnung, 5 in Ordnung.
 * ================================================================== */
function bw_befund(?array $c = null)
{
    if ($c === null) { $c = bw_config(false); }
    $stand = bw_stand_lesen();
    $lauf = bw_lauf_lesen();
    $ziele = bw_ziele($c);
    $p = bw_paths();

    /* WAEHREND EINER AKTUALISIERUNG EIN HINWEIS, KEIN FEHLER (I4, Durchgang
       30.09.2026). preupgrade.sh legt die Marke als Erstes an, postinstall.sh
       raeumt sie ab. Dazwischen fehlen Cron-Datei, Konfiguration und
       lauf.json, und bis 0.9.21 meldete der Healthcheck in dieser Luecke
       Stufe 3 oder 4 - cron.daily startet 02-pluginsupdate und
       03-healthcheck gleichzeitig, und healthcheck.pl legt das Ergebnis
       retained in den Broker (Pruefbericht Installer I4). */
    $marke = $p['lbhome'] !== ''
        ? $p['lbhome'] . '/data/plugins/' . $p['plugin'] . '.upgrade_laeuft' : '';
    if ($marke !== '' && is_file($marke)) {
        return array(6, bw_t('BEFUND.UPDATE_LAEUFT'));
    }

    $cron = $p['lbhome'] !== ''
        ? $p['lbhome'] . '/system/cron/cron.05min/' . $p['plugin'] : '';
    if ($cron !== '' && !is_file($cron)) {
        return array(3, sprintf(bw_t('BEFUND.CRON'), bw_e($cron)));
    }
    if (!bw_miniserver()) {
        return array(3, bw_t('BEFUND.KEIN_MS'));
    }
    if (!$ziele) {
        return array(3, bw_t('BEFUND.KEIN_ZIEL'));
    }
    if (empty($c['aktiv'])) {
        /* Ausgeschaltet ist KEIN Fehler. Ein Healthcheck, der auf jeder
           bewusst abgeschalteten Anlage rot steht, wird nicht mehr gelesen. */
        return array(6, bw_t('BEFUND.AUS'));
    }
    if ((int) $lauf['ts'] === 0) {
        /* Nach einem Update ist "noch nie gelaufen" falsch: purge_installation
           hat lauf.json geloescht, und der erste Takt steht noch aus.
           postinstall.sh legt dafuer eine Marke in den Datenordner, der Lauf
           nimmt sie weg (I4). */
        if (is_file($p['datadir'] . '/erster_takt_nach_update')) {
            return array(6, bw_t('BEFUND.NACH_UPDATE'));
        }
        return array(4, bw_t('BEFUND.NIE_GELAUFEN'));
    }
    $alter = time() - (int) $lauf['ts'];
    if ($alter > 1800) {
        return array(3, sprintf(bw_t('BEFUND.STEHT'), (int) round($alter / 60)));
    }
    $fehler = isset($stand['fehler']) ? (int) $stand['fehler'] : 0;
    if ($fehler >= 3) {
        return array(3, sprintf(bw_t('BEFUND.FEHLER'), $fehler,
                                (int) (isset($stand['code']) ? $stand['code'] : 0)));
    }
    if ($fehler > 0) {
        return array(4, sprintf(bw_t('BEFUND.FEHLER1'), $fehler));
    }
    $text = sprintf(bw_t('BEFUND.OK'), count($ziele), (int) round($alter / 60));
    /* Nur eine geltende Zaehlung (C3) - abgeschaltet hiesse der alte Wert
       sonst weiter "gemessen". */
    if (bw_zaehlung_gilt($c, $stand)) {
        $text .= ' ' . sprintf(bw_t('BEFUND.SCHARF'),
                               (int) $stand['scharf'], (int) $stand['automatiken']);
    }
    return array(5, $text);
}

/**
 * Den roten Punkt am Plugin-Symbol setzen - beim WECHSEL, nicht bei jedem Lauf.
 *
 * Eine Meldung je Durchgang ist keine Meldung, sondern Rauschen, und wer sie
 * abstellt, stellt auch die echte ab.
 */
function bw_melden(?array $c = null)
{
    list($stufe, $text) = bw_befund($c);
    $p = bw_paths();
    $merker = $p['datadir'] . '/.befund';
    $alt = is_file($merker) ? trim((string) @file_get_contents($merker)) : '';
    $neu = $stufe . '|' . md5($text);
    if ($alt === $neu) { return false; }
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    @file_put_contents($merker, $neu);
    /* notify_ext() steckt in loxberry_log.php, und die laedt weder der
       Cron noch die Oberflaeche von selbst - ein @ hilft gegen "undefined
       function" nicht, deshalb die Wache.

       Bis 0.9.16 fehlte das Nachladen, und die Wache schlug damit IMMER an:
       am Geraet gemessen (LoxBerry 4.0.0.15, 13.09.2026) ist
       function_exists('notify_ext') ohne loxberry_log.php false, mit ihr
       true. Es ging also nie eine Meldung hinaus - still, ohne eine Zeile
       im Protokoll. Der Satz "nicht jede LoxBerry-Fassung gleich bestueckt"
       stand hier als Begruendung und war eine Vermutung; gemessen ist das
       Gegenteil. Bauart aus oc_lib.php des Octopus-Plugins. */
    /* bw_paths() nennt den Schluessel lbhome, nicht home - mit dem
       falschen Namen waere $bw_liblog leer und is_file('') false,
       und die Behebung liefe ins Leere, ohne dass es auffiele. */
    /* Nur aus der Wurzel der Anlage. Ohne Wurzel hiess das bis 0.9.19
       /libs/phplib/loxberry_log.php ab der Laufwerkswurzel, und was dort
       lag, lief mit (Fall T2). */
    $bw_liblog = $p['lbhome'] !== '' ? $p['lbhome'] . '/libs/phplib/loxberry_log.php' : '';
    if ($bw_liblog !== '' && !function_exists('notify_ext') && is_file($bw_liblog)) {
        @require_once $bw_liblog;
    }
    if ($stufe <= 4 && function_exists('notify_ext')) {
        @notify_ext(array(
            'PACKAGE' => $p['plugin'], 'NAME' => 'beschattung',
            'MESSAGE' => $text,
            'SEVERITY' => ($stufe === 3 ? 3 : 4),
        ));
    }
    bw_log('Befund gewechselt: Stufe ' . $stufe . ' - ' . $text);
    return true;
}


/* ==================================================================
 * H. DIE VORLAGEN FUER LOXONE CONFIG
 * ==================================================================
 *
 * Nachbau des LoxBerry::LoxoneTemplateBuilder, wortgetreu uebernommen aus
 * ap_xml_virtual_in_http() der APC-UPS-Linie: Attributreihenfolge, CRLF als
 * Zeilenende und der Tabulator vor den Kindelementen entsprechen dem
 * Original. Nur das Kuerzel ist getauscht.
 *
 * DER KOMMENTAR WIRD IN LOXONE ZUM ANZEIGENAMEN, nicht zur Dokumentation -
 * das Feld Dokumentation bleibt leer. Deshalb steht am einzelnen Befehl eine
 * knappe Beschriftung, und alles, was erklaert werden muss, im Kommentar des
 * WURZELelements, den man einmal liest.
 * ================================================================== */
function bw_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function bw_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="' . bw_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . bw_x($kopf['title']) . '" ';
    $o .= 'Comment="' . bw_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . bw_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . bw_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $einheit = isset($c['einheit']) ? trim((string) $c['einheit']) : '';
        $unit = $einheit === '' ? '<v.1>' : '<v.1> ' . $einheit;
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . bw_x($c['title']) . '" ';
        $o .= 'Comment="' . bw_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . bw_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . bw_x(isset($c['min']) ? $c['min'] : 0) . '" ';
        $o .= 'MaxVal="' . bw_x(isset($c['max']) ? $c['max'] : 100) . '" ';
        $o .= 'Unit="' . bw_x($unit) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function bw_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="' . bw_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . bw_x($kopf['title']) . '" ';
    $o .= 'Comment="' . bw_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . bw_x($kopf['address']) . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . bw_x($c['title']) . '" ';
        $o .= 'Comment="' . bw_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . bw_x($c['on']) . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="' . bw_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="false" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Die Vorlage bauen. $art ist 'in' oder 'out'.
 * Rueckgabe: array(dateiname, inhalt)
 *
 * Der Dateiname traegt die Bauform vorne (VI_ Eingaenge, VQ_ Ausgaenge),
 * keine Leerzeichen und keine Umlaute.
 */
function bw_vorlage($art, ?array $c = null)
{
    if ($c === null) { $c = bw_config(); }
    $host = bw_wirtsname();
    $token = isset($c['aktionstoken']) ? (string) $c['aktionstoken'] : '';
    $hinweis = bw_t('VORLAGE.HINWEIS');
    if ($art === 'out') {
        /* Mit http:// wie die Eingangsvorlage und die Ausgangsvorlagen der
           uebrigen Linien (O6, Durchgang 30.09.2026). Bis 0.9.21 stand hier der
           blanke Rechnername, davor eine Zeile, die gleich wieder
           ueberschrieben wurde. Ob Loxone Config die blanke Form ebenso
           ausfuehrt, ist NICHT gemessen - die Form mit http:// ist die aller
           anderen Linien und der Ausfuhren in XML_Vorlagen_0.9.10. */
        $adresse = 'http://' . $host;
        $cmds = array();
        $cmds[] = array(
            'title'   => 'BW Befehl jetzt senden',
            'comment' => bw_t('VORLAGE.C_JETZT'),
            'on'      => bw_endpunkt_pfad(array('token' => $token, 'aktion' => 'jetzt'), true),
            'off'     => '',
        );
        if (!empty($c['pruefen_ein'])) {
            $cmds[] = array(
                'title'   => 'BW Automatiken zählen',
                'comment' => bw_t('VORLAGE.C_PRUEFEN'),
                'on'      => bw_endpunkt_pfad(array('token' => $token, 'aktion' => 'pruefen'), true),
                'off'     => '',
            );
        }
        /* Der DATEINAME bleibt ASCII (keine Umlaute, keine Leerzeichen);
           Titel und Anzeigename sind sichtbarer Text und tragen den Umlaut
           - Umschrift ist dort ein Textfehler, seit 0.9.14 berichtigt. */
        return array('VQ_Beschattungswaechter.xml', bw_xml_virtual_out(array(
            'title'   => 'Beschattungswächter Befehle',
            'comment' => 'Beschattungswächter (LoxBerry-Plugin)',
            /* Die Erklaerung der Befehle steht HIER, im Hinweis der Wurzel
               (O7): der Kommentar eines Befehls wird zum Anzeigenamen und
               bleibt deshalb unter 40 Zeichen. */
            'hint'    => $hinweis . ' ' . bw_t('VORLAGE.HINWEIS_BEFEHLE'),
            'address' => $adresse,
        ), $cmds));
    }
    $cmds = array();
    foreach (bw_felder() as $name => $i) {
        if (empty($i['zeile'])) { continue; }
        $cmds[] = array(
            'title'   => 'BW ' . $name,
            /* Der NAME, nicht die Erklaerung - Loxone Config macht daraus den
               Anzeigenamen des Bausteins (Desc), siehe bw_felder(). */
            'comment' => bw_t($i['kurz']),
            'check'   => bw_check($name),
            'einheit' => $i['einheit'],
            'min'     => $i['min'],
            'max'     => $i['max'],
        );
    }
    return array('VI_Beschattungswaechter.xml', bw_xml_virtual_in_http(array(
        'title'   => 'Beschattungswächter',
        'comment' => 'Beschattungswächter (LoxBerry-Plugin)',
        'hint'    => $hinweis,
        'address' => 'http://' . $host . bw_endpunkt_pfad(
                         array('token' => $token, 'aktion' => 'status'), true),
        'polling' => 60,
    ), $cmds));
}

/**
 * Der Name, unter dem der Miniserver diesen LoxBerry erreicht.
 *
 * Eine Adresse, die ein PROGRAMM benutzt, und eine, die ein MENSCH anklickt,
 * sind zwei verschiedene Dinge - und 127.0.0.1 heisst im Browser des
 * Anwenders dessen eigener Rechner. Hier ist der Miniserver der Aufrufer,
 * also gilt der Name, unter dem die Oberflaeche gerade erreicht wurde.
 * gethostname() ist nur die Rueckfallebene und ausdruecklich ein Vorschlag.
 */
function bw_wirtsname()
{
    $h = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $h = preg_replace('/[^A-Za-z0-9\.\-:]/', '', $h);
    if ($h !== '' && strpos($h, '127.0.0.1') !== 0 && strpos($h, 'localhost') !== 0) {
        return $h;
    }
    $n = (string) @gethostname();
    return $n !== '' ? $n : 'loxberry';
}


/* Der Escape-Helfer gehoert in die Bibliothek, nicht in
 * index.php: sonst steht er dem Endpunkt und jedem weiteren
 * Aufrufer nicht zur Verfuegung (Hausform, REGELN_2).
 *
 * ENT_SUBSTITUTE ist kein Zierat: ohne dieses Flag gibt htmlspecialchars()
 * bei ungueltigem UTF-8 die LEERE Zeichenkette zurueck. Gemessen am
 * 29.08.2026 unter 7.4 wie 8.4: 71 Byte Protokolltext mit einem einzigen
 * krummen Byte hinein, 0 Byte heraus - die ganze Protokollansicht war leer,
 * ohne ein Wort. Und das krumme Byte muss nicht von uns stammen: die rohe
 * Antwort des Miniservers landet im Protokoll. Mit ENT_SUBSTITUTE steht
 * stattdessen ein Ersatzzeichen da, und der Rest bleibt lesbar. */
function bw_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ein Formularwert - was kein Text ist, gibt es nicht.
 *
 * '?form[]=log' oder 'ms_nr[]=1' macht aus dem Wert ein FELD. Ein (string)
 * darauf ist unter 7.4 wie 8.4 eine Warnung ("Array to string conversion"),
 * und sie faellt VOR dem Wachposten und vor beiden Download-Handlern an -
 * also genau dort, wo eine Ausgabe jede spaetere header()-Zeile wirkungslos
 * macht. Dieselbe Festlegung wie bw_par() im Endpunkt, nur fuer die
 * angemeldete Seite.
 */
function bw_eingabe(array $quelle, $name)
{
    return isset($quelle[$name]) && is_string($quelle[$name]) ? trim($quelle[$name]) : '';
}

/**
 * Eine Zeichenkette auf hoechstens $n Zeichen kuerzen - an einer
 * Zeichengrenze, nicht an einer Bytegrenze.
 *
 * substr() schneidet Bytes. Faellt der Schnitt mitten in ein mehrbytiges
 * Zeichen, ist das Ergebnis kein gueltiges UTF-8 mehr, und die Maskierung
 * machte daraus bis 0.9.12 eine leere Spalte.
 */
function bw_gekuerzt($s, $n)
{
    $s = (string) $s;
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, (int) $n, 'UTF-8');
    }
    /* Ohne mbstring: an der letzten vollstaendigen Sequenz abschneiden. */
    $k = substr($s, 0, (int) $n);
    while ($k !== '' && !preg_match('//u', $k)) {
        $k = substr($k, 0, -1);
    }
    return $k;
}

/**
 * Ein Zeitpunkt fuer eine Kachel: heute nur die Uhrzeit, sonst mit Datum (O9,
 * Durchgang 30.09.2026). Bis 0.9.21 stand ein drei Tage alter Befehl als
 * "04:52:17" da - und sah aus wie einer von heute.
 */
function bw_zeitpunkt($ts)
{
    $ts = (int) $ts;
    return date('Y-m-d', $ts) === date('Y-m-d') ? date('H:i:s', $ts) : date('Y-m-d H:i:s', $ts);
}

/**
 * Die Einmalmeldung fuer Post/Redirect/Get (O1, Durchgang 30.09.2026), Bauart
 * BatterieBMS 0.9.30 und AudiConnect 0.9.22 nach Regeln/04 ("Jeder
 * POST-Handler endet mit einer Umleitung", Nachtrag Raumklima 0.11.8):
 * data/plugins/<ordner>/einmalmeldung.json, 0600, nur beim GET gelesen und
 * dabei geloescht, aelter als 120 s verworfen. Zugangsdaten stehen nie
 * darin: die Ergebnisse der Befehle tragen Adresse und Kennung, das Kennwort
 * des Miniservers geht nie in eine Adresse (bw_senden()).
 */
function bw_einmal_schreiben(array $daten)
{
    $p = bw_paths();
    if ($p['datadir'] === '') {
        return false;
    }
    $daten['zeit'] = time();
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($js) && bw_text_schreiben($p['datadir'] . '/einmalmeldung.json', $js, 0600);
}

/** Die Einmalmeldung lesen und loeschen - Rueckgabe null, wenn keine gilt. */
function bw_einmal_lesen()
{
    $f = bw_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $aus = array('meldungen' => array(), 'fehler' => array(), 'ergebnisse' => array(),
                 'zaehlung' => null, 'tab' => '');
    foreach (array('meldungen', 'fehler') as $k) {
        if (isset($d[$k]) && is_array($d[$k])) {
            foreach ($d[$k] as $m) {
                if (is_string($m)) { $aus[$k][] = $m; }
            }
        }
    }
    if (isset($d['ergebnisse']) && is_array($d['ergebnisse'])) {
        foreach ($d['ergebnisse'] as $r) {
            if (is_array($r) && isset($r['nr'], $r['code'])) { $aus['ergebnisse'][] = $r; }
        }
    }
    if (isset($d['zaehlung']) && is_array($d['zaehlung']) && isset($d['zaehlung']['zeilen'])
            && is_array($d['zaehlung']['zeilen'])) {
        $aus['zaehlung'] = $d['zaehlung'];
    }
    $aus['tab'] = (isset($d['tab']) && is_string($d['tab'])) ? $d['tab'] : '';
    /* X-2: die eingetippten Werte nach einer Beanstandung - nur, was
       bw_eingaben_pruefen() durchlaesst. */
    $aus['eingaben'] = isset($d['eingaben']) ? bw_eingaben_pruefen($d['eingaben']) : null;
    return $aus;
}

/* ==================================================================
 * Eingaben nach einer Beanstandung (Verbesserungsbau 30.09.2026, X-2 und
 * Entscheidung 16; Regeln/04 "Nach einer Beanstandung stehen die
 * eingetippten Werte wieder im Formular")
 *
 * Nur nach einer Beanstandung, nur das eine Formular und nur seine Felder.
 * Gespeichert wurde dann nichts. Nie Geheimnisse: das Wortzeichen der
 * Ecowitt-Weiche steht unter 'geheim' - es wird markiert, reist aber nie mit.
 * ================================================================== */

/** Die Felder je Formular: text, haken, geheim - null fuer ein unbekanntes. */
function bw_eingabe_felder($form)
{
    $ziele = array();
    for ($i = 2; $i <= 6; $i++) {
        $ziele[] = 'uuid' . $i;
        $ziele[] = 'befehl' . $i;
    }
    $felder = array(
        'settings' => array(
            'text'   => array_merge(array('ms_nr', 'uuid', 'befehl'), $ziele,
                                    array('von', 'bis', 'abstand', 'sonne_min', 'wind_max'),
                                    array('fassade', 'fassade2', 'fassade3', 'fassade4',
                                          'fassade5', 'fassade6')),
            'haken'  => array('aktiv', 'pruefen_ein', 'wetter_ein', 'sonne_ein'),
            'geheim' => array('wetter_token'),
        ),
        'mqtt' => array(
            'text'   => array('mqtt_thema'),
            'haken'  => array('mqtt_ein'),
            'geheim' => array(),
        ),
    );
    return isset($felder[$form]) ? $felder[$form] : null;
}

/** Taugt ein eingetippter Wert zum Mitreisen? Zeichenkette, gueltiges UTF-8, hoechstens 256 Byte. */
function bw_eingabe_reist($v)
{
    return is_string($v) && strlen($v) <= 256 && preg_match('//u', $v) === 1;
}

/** Die eingetippten Werte eines beanstandeten Formulars sammeln. */
function bw_eingaben_sammeln($form, array $post, array $markiert)
{
    $f = bw_eingabe_felder($form);
    if ($f === null) {
        return null;
    }
    $werte = array();
    foreach ($f['text'] as $k) {
        $v = (isset($post[$k]) && is_string($post[$k])) ? $post[$k] : '';
        if (bw_eingabe_reist($v)) {
            $werte[$k] = $v;
        }
    }
    foreach ($f['haken'] as $k) {
        $werte[$k] = empty($post[$k]) ? '0' : '1';
    }
    $alle = array_merge($f['text'], $f['haken'], $f['geheim']);
    $mark = array();
    foreach ($markiert as $k) {
        if (is_string($k) && in_array($k, $alle, true) && !in_array($k, $mark, true)) {
            $mark[] = $k;
        }
    }
    return array('form' => $form, 'werte' => $werte, 'markiert' => $mark);
}

/** Die Eingaben aus der Einmalmeldung pruefen - was nicht passt, faellt weg. */
function bw_eingaben_pruefen($roh)
{
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])) {
        return null;
    }
    $f = bw_eingabe_felder($roh['form']);
    if ($f === null) {
        return null;
    }
    $werte = array();
    if (isset($roh['werte']) && is_array($roh['werte'])) {
        foreach ($roh['werte'] as $k => $v) {
            /* Ein Geheimnis wird auch hier nicht angenommen. */
            if (is_string($k) && in_array($k, array_merge($f['text'], $f['haken']), true)
                    && bw_eingabe_reist($v)) {
                $werte[$k] = $v;
            }
        }
    }
    $alle = array_merge($f['text'], $f['haken'], $f['geheim']);
    $mark = array();
    if (isset($roh['markiert']) && is_array($roh['markiert'])) {
        foreach ($roh['markiert'] as $k) {
            if (is_string($k) && in_array($k, $alle, true) && !in_array($k, $mark, true)) {
                $mark[] = $k;
            }
        }
    }
    return array('form' => $roh['form'], 'werte' => $werte, 'markiert' => $mark);
}

/** Der Wert eines Feldes: nach einer Beanstandung der eingetippte, sonst der gespeicherte. */
function bw_formwert($eg, $name, $gespeichert)
{
    if (is_array($eg) && isset($eg['werte'][$name]) && is_string($eg['werte'][$name])) {
        return $eg['werte'][$name];
    }
    return (string) $gespeichert;
}

/** Ein Haken: nach einer Beanstandung der eingetippte Zustand, sonst der gespeicherte. */
function bw_formhaken($eg, $name, $gespeichert)
{
    if (is_array($eg) && isset($eg['werte'][$name])) {
        return $eg['werte'][$name] === '1';
    }
    return !empty($gespeichert);
}

/** Ist dieses Feld nach einer Beanstandung markiert? */
function bw_ist_markiert($eg, $name)
{
    return is_array($eg) && isset($eg['markiert']) && is_array($eg['markiert'])
        && in_array($name, $eg['markiert'], true);
}

/**
 * Die Merkmale eines beanstandeten Feldes - oder nichts.
 *
 * Rot umrandet (sm-beanstandet) UND fuer Bildschirmleser als ungueltig
 * gekennzeichnet (aria-invalid, B-Nachzug 01.10.2026, wie APC-UPS 1.2.16).
 * Bis 0.9.24 sah nur, wer die Farbe sah, welches Feld gemeint war.
 * $am_feld = false fuer ein <label> um einen Haken: dort nur der Rahmen;
 * aria-invalid gehoert an das Eingabeelement selbst (bw_ungueltig()).
 */
function bw_markiert($eg, $name, $am_feld = true)
{
    if (!bw_ist_markiert($eg, $name)) {
        return '';
    }
    return ' class="sm-beanstandet"' . ($am_feld ? ' aria-invalid="true"' : '');
}

/** Nur aria-invalid - fuer das <input> eines Hakens, dessen <label> den Rahmen traegt. */
function bw_ungueltig($eg, $name)
{
    return bw_ist_markiert($eg, $name) ? ' aria-invalid="true"' : '';
}

/**
 * Den eigenen Endpunkt WIRKLICH aufrufen - ueber 127.0.0.1, mit drei
 * Ausgaengen (O3, Regeln/04 "Die Selbstpruefung ruft den eigenen Endpunkt
 * wirklich auf"): 'gut' HTTP 200 mit der Selbsttestzeile, 'falsch' jede
 * andere Antwort (Code und Anfang des Rumpfes), 'stumm' keine Antwort - ein
 * Webserver, der nur eine Anfrage zugleich bedient, kann sich waehrend des
 * Seitenaufbaus nicht selbst aufrufen; das ist ein Hinweis, kein Kreuz.
 * Aufgerufen wird nur der Selbsttest, er loest nichts aus. Zeitschranke 3 s.
 * Die Art der Antwort wird am Merkmal $messbar getrennt vom Startwert
 * entschieden (Regeln/03, Govee 0.9.12).
 */
function bw_endpunkt_probe($token)
{
    $port = (isset($_SERVER['SERVER_PORT']) && preg_match('/^[0-9]{1,5}\z/', (string) $_SERVER['SERVER_PORT']))
        ? (int) $_SERVER['SERVER_PORT'] : 80;
    $url = 'http://127.0.0.1:' . $port
         . bw_endpunkt_pfad(array('token' => (string) $token, 'selftest' => '1'));
    $code = 0;
    $rumpf = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'LoxBerry Beschattungswaechter',
        ));
        $rumpf = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    } else {
        list($code, $rumpf) = bw_http_ohne_curl($url, array('Accept: text/plain'), 3);
    }
    $messbar = ($code > 0);
    if (!$messbar) {
        return array('lage' => 'stumm', 'code' => 0, 'anfang' => '');
    }
    $text = trim((string) $rumpf);
    if ($code === 200 && strpos($text, 'SELFTEST;OK=1;TOKEN=OK') === 0) {
        return array('lage' => 'gut', 'code' => 200, 'anfang' => $text);
    }
    return array('lage' => 'falsch', 'code' => $code, 'anfang' => bw_gekuerzt($text, 60));
}

/**
 * Stimmt die Themenliste mit dem Sendecode ueberein? (O3) In beide Richtungen
 * (Regeln/07, WiFi-Scanner-NG 3.2.4: "Je kuerzer die Liste, desto gruener
 * die Pruefung"): jedes gelistete Thema wird gesendet, und jedes gesendete
 * steht in der Liste. Verglichen wird bw_mqtt_themen() mit dem, was
 * bw_mqtt_werte() fuer einen Vollversand und bw_mqtt_zustaende_themen() fuer
 * den Aenderungsversand bilden - ohne zu senden.
 * Rueckgabe array(ok, Zahl gelistet, Zahl gesendet, nur gelistet, nur gesendet).
 */
function bw_mqtt_themen_pruefen(array $c)
{
    $w = bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '');
    $liste = array();
    foreach (array_keys(bw_mqtt_themen($c)) as $t) {
        $liste[] = (strpos($t, $w . '/') === 0) ? substr($t, strlen($w) + 1) : $t;
    }
    $senden = array_keys(bw_mqtt_werte($c, bw_stand_lesen()));
    foreach (bw_mqtt_zustaende_themen() as $t) {
        if (!in_array($t, $senden, true)) { $senden[] = $t; }
    }
    $nur_liste = array_values(array_diff($liste, $senden));
    $nur_senden = array_values(array_diff($senden, $liste));
    $ok = ($liste && $senden && !$nur_liste && !$nur_senden);
    return array($ok, count($liste), count($senden), $nur_liste, $nur_senden);
}

/**
 * Sind die Vorlagen fuer Loxone Config wohlgeformt? (O3) Beide werden gebaut
 * wie beim Herunterladen und mit simplexml gelesen - bis 0.9.21 geschah das
 * nur beim Herunterladen, und die Selbstpruefung hatte keine Zeile dafuer.
 * Rueckgabe: null ohne simplexml, sonst Dateiname => Zahl der Befehle, -1 wenn
 * nicht wohlgeformt.
 */
function bw_vorlagen_pruefen(array $c)
{
    if (!function_exists('simplexml_load_string')) {
        return null;
    }
    $aus = array();
    foreach (array('in' => 'VirtualInHttpCmd', 'out' => 'VirtualOutCmd') as $art => $kind) {
        list($name, $inhalt) = bw_vorlage($art, $c);
        $vorher = libxml_use_internal_errors(true);
        $x = simplexml_load_string($inhalt);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        $aus[$name] = ($x === false) ? -1 : count($x->xpath('//' . $kind));
    }
    return $aus;
}

/**
 * Die letzten $n nicht leeren Zeilen einer Datei, rueckwaerts mit fseek
 * gelesen (Regeln/03, "tail per exec() ist nie die Antwort") - fuer die
 * Fehlerausgabe des Cron-Laufs im Reiter Logdateien (C5).
 */
function bw_log_ende($f, $n = 20)
{
    clearstatcache(true, $f);
    if (!is_file($f) || !is_readable($f)) {
        return array();
    }
    $fh = @fopen($f, 'rb');
    if ($fh === false) {
        return array();
    }
    fseek($fh, 0, SEEK_END);
    $pos = ftell($fh);
    $puffer = '';
    while ($pos > 0 && substr_count($puffer, "\n") <= $n) {
        $schritt = min(4096, $pos);
        $pos -= $schritt;
        fseek($fh, $pos);
        $puffer = fread($fh, $schritt) . $puffer;
    }
    fclose($fh);
    $zeilen = array();
    foreach (preg_split('/\r?\n/', $puffer) as $z) {
        if (trim($z) !== '') { $zeilen[] = $z; }
    }
    return array_slice($zeilen, -$n);
}


/* ==================================================================
 * I. WETTER AUS DER ECOWITT-WEICHE (Wetter-1, Verbesserungsbau 30.09.2026)
 * ==================================================================
 *
 * AB WERK AUS (wetter_ein). Eingeschaltet haelt der TAKT einen faelligen
 * Befehl zurueck, solange die Sonne unter sonne_min liegt oder Wind bzw. Boe
 * ueber wind_max. "A druecken" ohne Sonne holt nur Automatiken zurueck, die
 * jemand bewusst abgeschaltet hat - und bewirkt sonst nichts.
 *
 * DIE QUELLE: der HTTP-Endpunkt der Ecowitt-Weiche, nie ihre Dateien.
 * Belegt in LoxBerry-Plugin-Ecowitt-Weiche-0.9.16: live.php reicht das JSON
 * der Station wortgetreu durch (common_list mit id/val), Ordner ecowittweiche
 * (plugin.cfg), Wortzeichen ?token= (nur, wenn dort eines gesetzt ist), HTTP
 * 503 ohne Daten, wenn beide Seiten ausfallen, Port des Webservers aus
 * general.json Webserver.Port. MQTT sendet die Weiche nicht.
 * Kennungen: 0x15 Solarstrahlung (in der Weiche benannt), 0x0B
 * Windgeschwindigkeit und 0x0C Boe (Eingaenge der Anlage, Projektdatei
 * 20260911_0753). Die Station schreibt die Einheit in val ("673.64 W/m2",
 * "1.2 m/s") oder in unit; eine fremde Einheit (Lux, Beaufort) heisst "ohne
 * Aussage".
 *
 * FAELLT DIE WEICHE AUS - kein guter Abruf innerhalb von bw_wetter_frist() -,
 * gilt das bisherige Verhalten: der Befehl geht ohne Wetterbedingung hinaus.
 * Eine Protokollzeile je Stunde, und der Reiter Test sagt es. Ein Wert ohne
 * Aussage haelt ebenfalls nichts zurueck.
 *
 * "jetzt" (Endpunkt, Knopf, --jetzt) fragt kein Wetter - wer den Befehl
 * schickt, meint ihn, wie bei Zeitfenster und Abstand.
 * ================================================================== */

/** Wie alt ein guter Wetterwert hoechstens sein darf (Sekunden). */
function bw_wetter_frist()
{
    return 600;
}

/** Der Pfad des Endpunkts der Weiche - ohne Wortzeichen, auch fuer Anzeige und Protokoll. */
function bw_wetter_pfad()
{
    return '/plugins/ecowittweiche/live.php';
}

/** Der Port des LoxBerry-Webservers (general.json Webserver.Port), sonst 80 - wie ew_webport(). */
function bw_webport()
{
    $p = bw_paths();
    $g = $p['lbhome'] !== '' ? $p['lbhome'] . '/config/system/general.json' : '';
    if ($g !== '' && is_file($g)) {
        $d = json_decode((string) @file_get_contents($g), true);
        $port = (is_array($d) && isset($d['Webserver']['Port']) && is_scalar($d['Webserver']['Port']))
            ? (int) $d['Webserver']['Port'] : 0;
        if ($port > 0 && $port <= 65535) {
            return $port;
        }
    }
    return 80;
}

/** Zahl und Einheit eines Eintrags aus common_list - array(null, '') ohne Aussage. */
function bw_wetter_eintrag(array $liste, $id)
{
    foreach ($liste as $e) {
        if (!is_array($e) || !isset($e['id']) || $e['id'] !== $id) {
            continue;
        }
        $v = (isset($e['val']) && is_scalar($e['val'])) ? trim((string) $e['val']) : '';
        /* "--" und "---.-" sind die Platzhalter der Station bei verlorenem
           Funk (Ecowitt-Weiche, README) - sie beginnen nicht mit einer Ziffer. */
        if (!preg_match('/^(-?[0-9]+(?:\.[0-9]+)?)\s*(.*)$/', $v, $m)) {
            return array(null, '');
        }
        $einheit = trim($m[2]);
        if ($einheit === '' && isset($e['unit']) && is_scalar($e['unit'])) {
            $einheit = trim((string) $e['unit']);
        }
        return array((float) $m[1], $einheit);
    }
    return array(null, '');
}

/** Solarstrahlung (0x15) in W/m2; null ohne Aussage oder in einer anderen Einheit. */
function bw_wetter_sonne(array $liste)
{
    list($z, $e) = bw_wetter_eintrag($liste, '0x15');
    if ($z === null) {
        return null;
    }
    $e = strtolower(str_replace(array(' ', "\xc2\xb2"), array('', '2'), $e));
    return ($e === '' || $e === 'w/m2') ? round($z, 1) : null;
}

/** Wind in m/s: der groessere Wert aus Windgeschwindigkeit (0x0B) und Boe (0x0C). */
function bw_wetter_wind(array $liste)
{
    $faktor = array('' => 1.0, 'm/s' => 1.0, 'km/h' => 1 / 3.6, 'kmh' => 1 / 3.6, 'mph' => 0.44704,
                    'knots' => 0.514444, 'knot' => 0.514444, 'kn' => 0.514444, 'kt' => 0.514444,
                    'ft/s' => 0.3048);
    $best = null;
    foreach (array('0x0B', '0x0C') as $id) {
        list($z, $e) = bw_wetter_eintrag($liste, $id);
        $e = strtolower(str_replace(' ', '', $e));
        if ($z === null || !isset($faktor[$e])) {
            continue;
        }
        $ms = $z * $faktor[$e];
        if ($best === null || $ms > $best) {
            $best = $ms;
        }
    }
    return $best === null ? null : round($best, 1);
}

/**
 * Die Weiche fragen - ueber 127.0.0.1 und den Port des LoxBerry-Webservers.
 * Rueckgabe: ok, grund (Kennwort), code (HTTP), sonne, wind, quelle.
 * Die Adresse mit dem Wortzeichen verlaesst diese Funktion nie.
 */
function bw_wetter_holen(array $c)
{
    $aus = array('ok' => false, 'grund' => '', 'code' => 0, 'sonne' => null, 'wind' => null, 'quelle' => '');
    $tok = isset($c['wetter_token']) ? trim((string) $c['wetter_token']) : '';
    $url = 'http://127.0.0.1:' . bw_webport() . bw_wetter_pfad()
         . ($tok !== '' ? '?token=' . rawurlencode($tok) : '');
    list($code, $roh) = bw_holen($url, array('Accept: application/json'), 4);
    $aus['code'] = (int) $code;
    if ($code === 0) {
        $aus['grund'] = 'STUMM';
    } elseif ($code === 403) {
        $aus['grund'] = 'TOKEN';
    } elseif ($code === 404) {
        $aus['grund'] = 'FEHLT';
    } elseif ($code === 503) {
        $aus['grund'] = 'AUSFALL';
    } elseif ($code !== 200) {
        $aus['grund'] = 'HTTP';
    }
    if ($aus['grund'] !== '') {
        return $aus;
    }
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['common_list']) || !is_array($d['common_list'])) {
        $aus['grund'] = 'FORM';
        return $aus;
    }
    $aus['ok'] = true;
    $aus['sonne'] = bw_wetter_sonne($d['common_list']);
    $aus['wind'] = bw_wetter_wind($d['common_list']);
    $aus['quelle'] = (isset($d['ew_quelle']) && is_string($d['ew_quelle']))
        ? substr(preg_replace('/[^a-z]/', '', $d['ew_quelle']), 0, 12) : '';
    return $aus;
}

/** Der gemerkte Stand (data/wetter.json): abruf, gut, entscheidung. Traegt kein Wortzeichen. */
function bw_wetter_lesen()
{
    $d = json_decode((string) @file_get_contents(bw_paths()['datadir'] . '/wetter.json'), true);
    return is_array($d) ? $d : array();
}

/** Einen Abruf in den gemerkten Stand eintragen. */
function bw_wetter_eintragen(array $d, array $abruf, $jetzt)
{
    $d['abruf'] = array('ts' => (int) $jetzt, 'ok' => $abruf['ok'] ? 1 : 0,
                        'grund' => (string) $abruf['grund'], 'code' => (int) $abruf['code']);
    if ($abruf['ok']) {
        $d['gut'] = array('ts' => (int) $jetzt, 'sonne' => $abruf['sonne'], 'wind' => $abruf['wind'],
                          'quelle' => (string) $abruf['quelle']);
    }
    return $d;
}

/**
 * Aus dem gemerkten Stand entscheiden - EINE Rechnung fuer Takt und Reiter
 * Test. lage: frisch (letzter Abruf gut), alt (letzter Abruf gescheitert, der
 * gute ist hoechstens bw_wetter_frist() alt), ausgefallen (bisheriges
 * Verhalten). sperrt: '', 'sonne' oder 'wind'.
 */
function bw_wetter_bewerten(array $c, array $d, $jetzt)
{
    $gut = (isset($d['gut']) && is_array($d['gut'])) ? $d['gut'] : null;
    $alter = $gut !== null ? max(0, (int) $jetzt - (int) (isset($gut['ts']) ? $gut['ts'] : 0)) : -1;
    $e = array('lage' => 'ausgefallen', 'sperrt' => '', 'sonne' => null, 'wind' => null, 'alter' => $alter,
               'smin' => (int) $c['sonne_min'], 'wmax' => (int) $c['wind_max']);
    if ($gut === null || $alter > bw_wetter_frist()) {
        return $e;
    }
    $e['lage'] = (!empty($d['abruf']['ok'])) ? 'frisch' : 'alt';
    $e['sonne'] = (isset($gut['sonne']) && is_numeric($gut['sonne'])) ? (float) $gut['sonne'] : null;
    $e['wind'] = (isset($gut['wind']) && is_numeric($gut['wind'])) ? (float) $gut['wind'] : null;
    if ($e['wmax'] > 0 && $e['wind'] !== null && $e['wind'] > $e['wmax']) {
        $e['sperrt'] = 'wind';
    } elseif ($e['smin'] > 0 && $e['sonne'] !== null && $e['sonne'] < $e['smin']) {
        $e['sperrt'] = 'sonne';
    }
    return $e;
}

/** Eine Zahl ohne Gebietsschema (Punkt), eine Nachkommastelle. */
function bw_wetter_zahl($z)
{
    return $z === null ? '-' : number_format((float) $z, 1, '.', '');
}

/** Die Entscheidung als deutscher Satz fuer Protokoll und Ausgabe des Laufs. */
function bw_wetter_protokollsatz(array $e)
{
    if ($e['lage'] === 'ausgefallen') {
        return 'keine frischen Wetterdaten der Ecowitt-Weiche - bisheriges Verhalten, ohne Wetterbedingung';
    }
    $werte = 'Sonne ' . bw_wetter_zahl($e['sonne']) . ' W/m2, Wind ' . bw_wetter_zahl($e['wind']) . ' m/s';
    if ($e['sperrt'] === 'wind') {
        return 'Wind ueber ' . $e['wmax'] . ' m/s (' . $werte . ') - Befehl zurueckgehalten';
    }
    if ($e['sperrt'] === 'sonne') {
        return 'Sonne unter ' . $e['smin'] . ' W/m2 (' . $werte . ') - Befehl zurueckgehalten';
    }
    return 'Wetter erlaubt den Befehl (' . $werte . ')';
}

/** Die Entscheidung als Satz fuer die Oberflaeche (uebersetzt, Klartext). */
function bw_wetter_satz(array $e)
{
    if ($e['lage'] === 'ausgefallen') {
        return bw_t('TEXT.WETTER_BISHERIG');
    }
    if ($e['sperrt'] === 'wind') {
        return sprintf(bw_t('TEXT.WETTER_WIND'), bw_wetter_zahl($e['wind']), $e['wmax']);
    }
    if ($e['sperrt'] === 'sonne') {
        return sprintf(bw_t('TEXT.WETTER_SONNE'), bw_wetter_zahl($e['sonne']), $e['smin']);
    }
    return bw_t('TEXT.WETTER_ERLAUBT');
}

/**
 * Die Wetterbedingung des Takts: die Weiche fragen, den Abruf merken,
 * entscheiden. $schreiben = false (Probe): nichts ablegen, nichts
 * protokollieren.
 */
function bw_wetter_entscheiden(array $c, $schreiben = true, $jetzt = null)
{
    $jetzt = ($jetzt === null) ? time() : (int) $jetzt;
    $abruf = bw_wetter_holen($c);
    $d = bw_wetter_eintragen(bw_wetter_lesen(), $abruf, $jetzt);
    $e = bw_wetter_bewerten($c, $d, $jetzt);
    $e['abruf'] = $abruf;
    $d['entscheidung'] = array('ts' => $jetzt, 'lage' => $e['lage'], 'sperrt' => $e['sperrt']);
    if ($schreiben) {
        bw_json_schreiben(bw_paths()['datadir'] . '/wetter.json', $d, 0644);
        if ($e['lage'] === 'ausgefallen') {
            bw_log_wenn_neu('wetter_aus',
                'Wetter: die Ecowitt-Weiche lieferte seit mehr als ' . (int) round(bw_wetter_frist() / 60)
                . ' Minuten keinen brauchbaren Wert (zuletzt ' . $abruf['grund'] . ', HTTP ' . (int) $abruf['code']
                . ') - es gilt das bisherige Verhalten: der Befehl geht ohne Wetterbedingung hinaus.');
        }
    }
    return $e;
}

/* ==================================================================
 * J. SONNENSTAND AUS DER FENSTERBILANZ (Sonne-1, Verbesserungsbau
 *    01.10.2026, Teil Beschattungswaechter)
 * ==================================================================
 *
 * AB WERK AUS (sonne_ein). Eingeschaltet laesst der TAKT das A bei einem
 * Ziel weg, solange die Fensterbilanz fuer JEDE diesem Ziel zugeordnete
 * Fassade sagt, dass gerade keine direkte Sonne auf ihre Fenster faellt.
 * Ohne Sonne holt der Befehl nur Automatiken zurueck, die jemand bewusst
 * abgeschaltet hat, und bewirkt sonst nichts (Fensterbilanz-c1,
 * Beschattung-c1). Ein Ziel ohne Zuordnung wird gedrueckt wie bisher.
 *
 * DIE QUELLE: die MQTT-Themen der Fensterbilanz unter haus/sonne/, nie ihre
 * Dateien. Belegt in LoxBerry-Plugin-Beschattung_Fensterbilanz-0.12.13
 * (veroeffentlicht 0.12.12), fb_lib.php: fb_sonne_stamm() "haus/sonne",
 * fb_sonne_nachrichten() (Namen, Werte, Reihenfolge: azimut, elevation,
 * fassaden, fassade/<az>/wirkt, ts ZULETZT), fb_sonne_fassaden() (Regel
 * "wirkt": Geometrie, keine Wolken), gesendet in fb_mqtt_senden() mit
 * "publish" ueber den UDP-Eingang des Gateways - also FLUECHTIG, in jedem
 * Rechenlauf (Cron alle 5 min, dazu bei Messwerten hoechstens im Rechentakt).
 * <az> ist die Ausrichtung in ganzen Grad 0-359 ohne fuehrende Null.
 *
 * WEIL NICHTS RETAINED IST, hoert ein kleiner Begleitprozess mit
 * (bw_lauf.php --sonne-hoeren <kennung>). Der Takt startet ihn alle fuenf
 * Minuten neu und traegt dessen Kennung in data/sonne_hoerer.json ein; der
 * vorige endet, sobald dort eine andere Kennung steht (Pruefung jede
 * Sekunde), spaetestens nach bw_sonne_hoerer_dauer() - steht der Takt, steht
 * nach sieben Minuten auch kein Begleitprozess mehr. Er schreibt nur
 * data/sonne.json. Anmeldung am Broker wie bw_mqtt_behalten_liste():
 * Brokeruser/Brokerpass aus general.json, das Kennwort steht nur im
 * CONNECT-Paket, nie auf einer Kommandozeile oder in einer Datei des Plugins.
 *
 * FAELLT DIE QUELLE AUS - kein Satz, Satz aelter als bw_sonne_frist() oder
 * aus der Zukunft, die Fassade fehlt im Satz -, gilt fuer das Ziel das
 * bisherige Verhalten: das A wird gedrueckt, das Protokoll sagt es hoechstens
 * stuendlich, der Reiter Test zeigt es. Nie wird aufgrund alter Daten still
 * unterdrueckt.
 *
 * NACHHOLEN: ein weggelassenes Ziel prueft jeder folgende Takt im
 * Zeitfenster erneut und drueckt es, sobald Sonne darauf faellt oder die
 * Quelle schweigt. Der Abstand der uebrigen Ziele zaehlt dabei weiter ab dem
 * regulaeren Befehl (bw_sonne_takt_stand()).
 *
 * "jetzt" (Endpunkt, Knopf, --jetzt) fragt den Sonnenstand nicht - wer den
 * Befehl schickt, meint ihn, wie bei Wetter, Zeitfenster und Abstand.
 * ================================================================== */

/**
 * Wie alt ein Satz hoechstens sein darf (Sekunden): 900 = dreimal der
 * Fuenfminutentakt der Fensterbilanz (ihr BAUBERICHT nennt dieselbe Grenze;
 * Entscheidung 4 rechnet ebenso). Ein Satz kommt mindestens alle 5 min; der
 * UDP-Eingang verliert unter Last Datagramme (Regeln/07, 12,6 % an dieser
 * Anlage), zwei verlorene Saetze in Folge sollen nicht sofort auf das
 * bisherige Verhalten fallen. In 15 min wandert die Sonne rund 4 Grad im
 * Azimut; ein "keine Sonne" kann also nur an der Kante einer Fassade
 * veraltet sein - und dort holt der naechste frische Satz das A im
 * naechsten Takt nach.
 */
function bw_sonne_frist()
{
    return 900;
}

/** Wie weit ein Satz aus der Zukunft stammen darf (Uhrsprung) - 5 s wie Fensterbilanz a1. */
function bw_sonne_zukunft()
{
    return 5;
}

/** Hoechste Lebensdauer eines Begleitprozesses (s): ein Takt (300) plus zwei Minuten Verzug. */
function bw_sonne_hoerer_dauer()
{
    return 420;
}

/** Der Themenstamm der Fensterbilanz (fb_sonne_stamm()). */
function bw_sonne_stamm()
{
    return 'haus/sonne';
}

function bw_sonne_pfad()
{
    return bw_paths()['datadir'] . '/sonne.json';
}

function bw_sonne_hoerer_pfad()
{
    return bw_paths()['datadir'] . '/sonne_hoerer.json';
}

/** Der gemerkte Stand des Begleitprozesses: satz, hoerer. */
function bw_sonne_lesen()
{
    $d = json_decode((string) @file_get_contents(bw_sonne_pfad()), true);
    return is_array($d) ? $d : array();
}

/** Die zugeordneten Fassaden eines Ziels (ganze Grad) - leer heisst: keine Zuordnung. */
function bw_sonne_fassaden_von(array $c, $nr)
{
    $k = ((int) $nr === 1) ? 'fassade' : 'fassade' . (int) $nr;
    $s = isset($c[$k]) && (is_string($c[$k]) || is_int($c[$k])) ? trim((string) $c[$k]) : '';
    if ($s === '' || !bw_wert_pruefen('fassade', $s)) {
        return array();
    }
    return array_values(array_unique(array_map('intval', explode(',', $s))));
}

/**
 * Den gemerkten Satz bewerten - EINE Rechnung fuer Takt und Reiter Test.
 * lage: frisch | veraltet | zukunft | nie. Nur ein frischer Satz traegt
 * Werte; sonst ist wirkt leer, und jedes zugeordnete Ziel faellt auf das
 * bisherige Verhalten.
 */
function bw_sonne_bewerten(array $d, $jetzt)
{
    $e = array('lage' => 'nie', 'alter' => -1, 'ts' => 0, 'azimut' => null, 'elevation' => null,
               'fassaden' => null, 'wirkt' => array());
    $satz = (isset($d['satz']) && is_array($d['satz'])) ? $d['satz'] : null;
    if ($satz === null || !isset($satz['ts']) || !is_int($satz['ts']) || $satz['ts'] <= 0) {
        return $e;
    }
    $e['ts'] = $satz['ts'];
    $e['alter'] = (int) $jetzt - $satz['ts'];
    if ($e['alter'] < -bw_sonne_zukunft()) {
        $e['lage'] = 'zukunft';
        return $e;
    }
    if ($e['alter'] > bw_sonne_frist()) {
        $e['lage'] = 'veraltet';
        return $e;
    }
    $e['lage'] = 'frisch';
    foreach (array('azimut', 'elevation') as $k) {
        if (isset($satz[$k]) && (is_int($satz[$k]) || is_float($satz[$k]))) {
            $e[$k] = (float) $satz[$k];
        }
    }
    if (isset($satz['fassaden']) && is_array($satz['fassaden'])) {
        $e['fassaden'] = array();
        foreach ($satz['fassaden'] as $az) {
            if (is_int($az) && $az >= 0 && $az <= 359) {
                $e['fassaden'][] = $az;
            }
        }
    }
    if (isset($satz['wirkt']) && is_array($satz['wirkt'])) {
        foreach ($satz['wirkt'] as $az => $w) {
            if (is_int($az) && $az >= 0 && $az <= 359 && ($w === 0 || $w === 1)) {
                $e['wirkt'][$az] = $w;
            }
        }
    }
    return $e;
}

/**
 * Je Ziel ein Urteil: frei (keine Zuordnung - wie bisher), sonne (Sonne auf
 * mindestens einer zugeordneten Fassade), ohne_sonne (JEDE zugeordnete
 * Fassade sagt im frischen Satz 0 - das A wird weggelassen), keine_aussage
 * (kein frischer Satz oder eine Fassade fehlt darin - bisheriges Verhalten).
 */
function bw_sonne_ziele(array $c, array $ziele, array $e)
{
    $aus = array();
    foreach ($ziele as $z) {
        $fa = bw_sonne_fassaden_von($c, $z['nr']);
        $u = array('nr' => (int) $z['nr'], 'uuid' => $z['uuid'], 'befehl' => $z['befehl'],
                   'fassaden' => $fa, 'urteil' => 'frei', 'fehlt' => array());
        if ($fa) {
            if ($e['lage'] !== 'frisch') {
                $u['urteil'] = 'keine_aussage';
            } else {
                $sonne = false;
                foreach ($fa as $az) {
                    if (!isset($e['wirkt'][$az])) {
                        $u['fehlt'][] = $az;
                    } elseif ($e['wirkt'][$az] === 1) {
                        $sonne = true;
                    }
                }
                $u['urteil'] = $sonne ? 'sonne' : ($u['fehlt'] ? 'keine_aussage' : 'ohne_sonne');
            }
        }
        $aus[] = $u;
    }
    return $aus;
}

/** Die Lage des Satzes als deutscher Halbsatz fuer Protokoll und Ausgabe des Laufs. */
function bw_sonne_lage_satz(array $e)
{
    if ($e['lage'] === 'frisch') {
        return 'Satz der Fensterbilanz ' . max(0, (int) $e['alter']) . ' s alt';
    }
    if ($e['lage'] === 'veraltet') {
        return 'letzter Satz der Fensterbilanz ' . (int) $e['alter'] . ' s alt (Grenze ' . bw_sonne_frist() . ' s)';
    }
    if ($e['lage'] === 'zukunft') {
        return 'letzter Satz der Fensterbilanz ' . (-(int) $e['alter']) . ' s in der Zukunft';
    }
    return 'kein Satz der Fensterbilanz empfangen';
}

/**
 * Die Sonnenbedingung des Takts fuer die faelligen Ziele.
 * Rueckgabe: frei (Ziele, die gedrueckt werden, Form wie bw_ziele()), weg
 * (Nummern ohne Sonne), ohne (Nummern ohne Aussage, gedrueckt), bewertung,
 * urteile. $schreiben = false (Probe): nichts protokollieren.
 */
function bw_sonne_entscheiden(array $c, array $ziele, $schreiben = true, $jetzt = null)
{
    $jetzt = ($jetzt === null) ? time() : (int) $jetzt;
    $e = bw_sonne_bewerten(bw_sonne_lesen(), $jetzt);
    $urteile = bw_sonne_ziele($c, $ziele, $e);
    $s = array('frei' => array(), 'weg' => array(), 'ohne' => array(), 'bewertung' => $e, 'urteile' => $urteile);
    foreach ($urteile as $u) {
        if ($u['urteil'] === 'ohne_sonne') {
            $s['weg'][] = $u['nr'];
            continue;
        }
        $s['frei'][] = array('nr' => $u['nr'], 'uuid' => $u['uuid'], 'befehl' => $u['befehl']);
        if ($u['urteil'] === 'keine_aussage') {
            $s['ohne'][] = $u['nr'];
        }
    }
    if ($schreiben) {
        /* Hoechstens einmal je Stunde und Menge: ein Takt, der alle fuenf
           Minuten dasselbe weglaesst, schriebe sonst zwoelf Zeilen je Stunde. */
        if ($s['weg']) {
            bw_log_wenn_neu('sonne_weg_' . implode('_', $s['weg']),
                'Sonne-1: ' . bw_sonne_protokollsatz(array('weg' => $s['weg'], 'ohne' => array(), 'bewertung' => $e)));
        }
        if ($s['ohne']) {
            /* Je Lage und Zielmenge: ein Wechsel (kein Satz -> veraltet) steht sofort da. */
            bw_log_wenn_neu('sonne_ohne_' . $e['lage'] . '_' . implode('_', $s['ohne']),
                'Sonne-1: ' . bw_sonne_protokollsatz(array('weg' => array(), 'ohne' => $s['ohne'], 'bewertung' => $e)));
        }
    }
    return $s;
}

/** Die Entscheidung als deutscher Satz fuer Protokoll und Ausgabe des Laufs. */
function bw_sonne_protokollsatz(array $s)
{
    $e = $s['bewertung'];
    $teile = array();
    if ($s['weg']) {
        $teile[] = 'keine Sonne auf den Fassaden von Ziel ' . implode(', ', $s['weg'])
                 . ' (' . bw_sonne_lage_satz($e) . ') - das A wird dort weggelassen';
    }
    if ($s['ohne']) {
        $teile[] = 'keine frische Aussage fuer Ziel ' . implode(', ', $s['ohne'])
                 . ' (' . bw_sonne_lage_satz($e) . ') - bisheriges Verhalten, das A wird gedrueckt';
    }
    if (!$teile) {
        $teile[] = 'Sonne erlaubt den Befehl (' . bw_sonne_lage_satz($e) . ')';
    }
    return implode('; ', $teile);
}

/**
 * Der Sonnen-Stand des Takts in stand.json - nur, wenn der LETZTE Befehl
 * (stand.letzte) ein Befehl des Takts mit Sonnenbedingung war. Jeder andere
 * Befehl (jetzt, Endpunkt, Knopf, Takt ohne Haken) setzt letzte neu, und
 * dann gilt dieser Stand nicht mehr. Rueckgabe null oder
 * array('regel' => Zeitpunkt des regulaeren Befehls, 'offen' => weggelassene Ziele).
 */
function bw_sonne_takt_stand(array $stand)
{
    $s = (isset($stand['sonne']) && is_array($stand['sonne'])) ? $stand['sonne'] : null;
    if ($s === null || !isset($s['letzte'], $s['regel'], $stand['letzte'])
        || (int) $s['letzte'] !== (int) $stand['letzte']
        || (int) $s['regel'] <= 0 || (int) $s['regel'] > (int) $s['letzte']) {
        return null;
    }
    $offen = array();
    foreach ((isset($s['offen']) && is_array($s['offen'])) ? $s['offen'] : array() as $n) {
        if (is_int($n) && $n >= 1 && $n <= 6 && !in_array($n, $offen, true)) {
            $offen[] = $n;
        }
    }
    return array('regel' => (int) $s['regel'], 'offen' => $offen);
}

/** Die noch offenen (weggelassenen) Ziele, die es noch gibt - in der Reihenfolge von $ziele. */
function bw_sonne_offene_ziele(array $stand, array $ziele)
{
    $s = bw_sonne_takt_stand($stand);
    if ($s === null || !$s['offen']) {
        return array();
    }
    $aus = array();
    foreach ($ziele as $z) {
        if (in_array((int) $z['nr'], $s['offen'], true)) {
            $aus[] = $z;
        }
    }
    return $aus;
}

/**
 * Nach einem Befehl des Takts eintragen, was weggelassen wurde und ab wann
 * der Abstand zaehlt: bei einem nachgeholten Befehl bleibt der Zeitpunkt des
 * regulaeren stehen. Laeuft NACH bw_stand_nach_senden().
 */
function bw_sonne_stand_eintragen(array $stand, array $sonne, $vorher, $nachholen)
{
    $letzte = isset($stand['letzte']) ? (int) $stand['letzte'] : time();
    $regel = ($nachholen && is_array($vorher)) ? (int) $vorher['regel'] : $letzte;
    $stand['sonne'] = array('letzte' => $letzte, 'regel' => $regel,
                            'offen' => array_values(array_map('intval', $sonne['weg'])));
    return $stand;
}

/** Steht diese Kennung (noch) in data/sonne_hoerer.json? */
function bw_sonne_hoerer_gilt($id)
{
    $d = json_decode((string) @file_get_contents(bw_sonne_hoerer_pfad()), true);
    return is_array($d) && isset($d['id']) && is_string($d['id']) && $d['id'] === (string) $id;
}

/**
 * Den Begleitprozess neu starten - aus dem Takt (bin/bw_lauf.php), nicht bei
 * --probe und --jetzt. Erst die neue Kennung eintragen (der vorige endet
 * daran), dann starten. KEIN proc_close(): das wartete auf das Ende; der
 * Prozess laeuft nach dem Takt weiter und endet von selbst. Seine Ein- und
 * Ausgaben gehen nach /dev/null, Fehler nach cron.err - eine offene Leitung
 * des Takts haelt er nie. Gestartet wird VOR jeder Sperre des Takts
 * (Memory "Sperre vererbt sich an Kinder").
 */
function bw_sonne_hoerer_starten($skript)
{
    $p = bw_paths();
    try {
        $id = bin2hex(random_bytes(8));
    } catch (\Throwable $t) {
        $id = substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
    }
    if (!bw_json_schreiben(bw_sonne_hoerer_pfad(), array('id' => $id, 'start' => time()), 0644)) {
        bw_log_wenn_neu('sonne_start',
            'Sonne-1: data/sonne_hoerer.json liess sich nicht schreiben - das Mithoeren wurde nicht '
            . 'gestartet; sobald der letzte Satz aelter als ' . (int) round(bw_sonne_frist() / 60)
            . ' Minuten ist, gilt das bisherige Verhalten.');
        return false;
    }
    $null = (DIRECTORY_SEPARATOR === '/') ? '/dev/null' : 'NUL';
    $err = (is_dir($p['log']) && is_writable($p['log'])) ? array('file', $p['log'] . '/cron.err', 'a')
                                                           : array('file', $null, 'w');
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $rohre = array();
    $proc = @proc_open(array($php, (string) $skript, '--sonne-hoeren', $id),
                       array(0 => array('file', $null, 'r'), 1 => array('file', $null, 'w'), 2 => $err),
                       $rohre);
    if (!is_resource($proc)) {
        bw_log_wenn_neu('sonne_start',
            'Sonne-1: das Mithoeren (bw_lauf.php --sonne-hoeren) liess sich nicht starten; sobald der letzte '
            . 'Satz aelter als ' . (int) round(bw_sonne_frist() / 60) . ' Minuten ist, gilt das bisherige Verhalten.');
        return false;
    }
    return true;
}

/** Ausgeschaltet: die Kennung austragen - der laufende Begleitprozess endet daran binnen einer Sekunde. */
function bw_sonne_hoerer_abmelden()
{
    $f = bw_sonne_hoerer_pfad();
    if (is_file($f)) {
        @unlink($f);
        bw_log('Sonne-1: ausgeschaltet - das Mithoeren am Broker endet.');
    }
}

/** Die Zugangsdaten des Brokers aus general.json - wie bw_mqtt_behalten_liste(); null ohne Eintrag. */
function bw_sonne_broker()
{
    $p = bw_paths();
    if ($p['lbhome'] === '') {
        return null;
    }
    $d = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) {
        return null;
    }
    $m = $d['Mqtt'];
    $hol = function ($k) use ($m) {
        return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : '';
    };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') {
        $host = '127.0.0.1';
    }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) {
        $port = 1883;
    }
    return array('host' => $host, 'port' => $port, 'user' => $hol('Brokeruser'), 'pass' => $hol('Brokerpass'));
}

/**
 * Aus den seit dem letzten ts empfangenen Themen einen Satz bauen. Es zaehlt
 * nur, was hoechstens 10 s vor dem ts kam: die Fensterbilanz sendet einen
 * Satz in einem Zug (5 ms je Thema). Was aelter ist, stammt aus einem Satz,
 * dessen ts verloren ging - es gilt nicht, sonst trueg ein alter Wert den
 * neuen Zeitstempel. null, wenn der ts selbst unbrauchbar ist.
 */
function bw_sonne_satz_bauen(array $offen, $ts_roh, $jetzt_mikro)
{
    $ts_roh = trim((string) $ts_roh);
    if (preg_match('/^[0-9]{1,12}\z/', $ts_roh) !== 1 || (int) $ts_roh <= 0) {
        return null;
    }
    $satz = array('ts' => (int) $ts_roh, 'empfangen' => (int) floor($jetzt_mikro), 'azimut' => null,
                  'elevation' => null, 'fassaden' => null, 'wirkt' => array());
    $stamm = bw_sonne_stamm() . '/';
    foreach ($offen as $t => $e) {
        if ($jetzt_mikro - $e[1] > 10.0) {
            continue;
        }
        $w = trim((string) $e[0]);
        $rest = (string) substr((string) $t, strlen($stamm));
        if ($rest === 'azimut' || $rest === 'elevation') {
            if (preg_match('/^-?[0-9]{1,3}(\.[0-9]+)?\z/', $w) === 1) {
                $satz[$rest] = (float) $w;
            }
        } elseif ($rest === 'fassaden') {
            if ($w === '-') {
                $satz['fassaden'] = array();
            } elseif (preg_match('/^[0-9]{1,3}(,[0-9]{1,3}){0,63}\z/', $w) === 1) {
                $satz['fassaden'] = array_map('intval', explode(',', $w));
            }
        } elseif (preg_match('#^fassade/(0|[1-9][0-9]{0,2})/wirkt\z#', $rest, $m) === 1
                  && (int) $m[1] <= 359 && ($w === '0' || $w === '1')) {
            $satz['wirkt'][(int) $m[1]] = (int) $w;
        }
    }
    return $satz;
}

/** Den Stand des Begleitprozesses ablegen - nur, solange seine Kennung gilt. */
function bw_sonne_merken($id, array $hoerer, $satz = null)
{
    if (!bw_sonne_hoerer_gilt($id)) {
        return false;
    }
    $d = bw_sonne_lesen();
    $d['hoerer'] = $hoerer;
    if ($satz !== null) {
        $d['satz'] = $satz;
    }
    return bw_json_schreiben(bw_sonne_pfad(), $d, 0644);
}

/**
 * Der Begleitprozess (bw_lauf.php --sonne-hoeren <kennung>): am Broker
 * haus/sonne/# abonnieren (QoS 0) und jeden vollstaendigen Satz ablegen.
 * MQTT 3.1.1 von Hand wie bw_mqtt_behalten_liste(), ohne fremde Bibliothek.
 * Endet, sobald die Kennung nicht mehr gilt, der Haken aus ist (Pruefung
 * jede Minute), die Verbindung reisst oder $dauer um ist.
 * Rueckgabe: 0 regulaer beendet, 1 Broker nicht zu erreichen/abgewiesen/
 * getrennt, 2 Kennung ungueltig.
 */
function bw_sonne_hoeren($id, $dauer = null)
{
    $dauer = ($dauer === null) ? bw_sonne_hoerer_dauer() : max(1, (int) $dauer);
    $id = (string) $id;
    if (preg_match('/^[0-9a-f]{16}\z/', $id) !== 1 || !bw_sonne_hoerer_gilt($id)) {
        return 2;
    }
    $start = time();
    $z = array('id' => $id, 'pid' => getmypid(), 'start' => $start, 'verbunden' => 0, 'grund' => '',
               'code' => 0, 'nachrichten' => 0, 'saetze' => 0, 'ende' => 0);
    $b = bw_sonne_broker();
    if ($b === null) {
        $z['grund'] = 'KEIN_BROKER';
        $z['ende'] = time();
        bw_sonne_merken($id, $z);
        bw_log_wenn_neu('sonne_hoerer_broker',
            'Sonne-1: in der general.json steht kein MQTT-Broker - der Sonnenstand der Fensterbilanz ist nicht '
            . 'zu lesen; es gilt das bisherige Verhalten.');
        return 1;
    }
    $eno = 0;
    $etxt = '';
    $s = @stream_socket_client('tcp://' . $b['host'] . ':' . $b['port'], $eno, $etxt, 3);
    if (!$s) {
        $z['grund'] = 'STUMM';
        $z['ende'] = time();
        bw_sonne_merken($id, $z);
        bw_log_wenn_neu('sonne_hoerer_stumm',
            'Sonne-1: der MQTT-Broker ' . $b['host'] . ':' . $b['port'] . ' antwortet nicht (' . $etxt
            . ') - es gilt das bisherige Verhalten, sobald der letzte Satz aelter als '
            . (int) round(bw_sonne_frist() / 60) . ' Minuten ist.');
        return 1;
    }
    stream_set_timeout($s, 5);
    $zk = function ($t) {
        return pack('n', strlen($t)) . $t;
    };
    $laenge = function ($n) {
        $o = '';
        do {
            $by = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) {
                $by |= 128;
            }
            $o .= chr($by);
        } while ($n > 0);
        return $o;
    };
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) {
                    return null;
                }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) {
            return null;
        }
        $n = 0;
        $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $by = $lies(1);
            if ($by === null) {
                return null;
            }
            $n += (ord($by) & 127) * $mult;
            $mult *= 128;
            if (!(ord($by) & 128)) {
                break;
            }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };
    $flags = 0x02;
    $nutz = $zk('bwsonne' . getmypid());
    if ($b['user'] !== '') {
        $flags |= 0x80;
        if ($b['pass'] !== '') {
            $flags |= 0x40;
        }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 60);
    if ($b['user'] !== '') {
        $nutz .= $zk($b['user']);
        if ($b['pass'] !== '') {
            $nutz .= $zk($b['pass']);
        }
    }
    $ack = null;
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
    }
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2 || ord($ack[1][1]) !== 0) {
        $z['grund'] = 'ANMELDUNG';
        $z['code'] = ($ack !== null && strlen($ack[1]) >= 2) ? ord($ack[1][1]) : 0;
        $z['ende'] = time();
        fclose($s);
        bw_sonne_merken($id, $z);
        bw_log_wenn_neu('sonne_hoerer_anmeldung',
            'Sonne-1: der MQTT-Broker hat die Anmeldung abgewiesen (CONNACK ' . (int) $z['code']
            . ') - Brokeruser/Brokerpass in der general.json pruefen; es gilt das bisherige Verhalten.');
        return 1;
    }
    $filter = bw_sonne_stamm() . '/#';
    $sub = pack('n', 1) . $zk($filter) . chr(0);
    @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
    $suback = null;
    $bis = microtime(true) + 5.0;
    while ($suback === null && microtime(true) < $bis) {
        $pk = $paket();
        if ($pk === null) {
            break;
        }
        if (($pk[0] >> 4) === 9) {
            $suback = (string) substr($pk[1], 2);
        }
    }
    if ($suback === null || strlen($suback) !== 1 || ord($suback[0]) >= 0x80) {
        $z['grund'] = 'ABGELEHNT';
        $z['ende'] = time();
        @fwrite($s, chr(0xE0) . chr(0));
        fclose($s);
        bw_sonne_merken($id, $z);
        bw_log_wenn_neu('sonne_hoerer_abo',
            'Sonne-1: der MQTT-Broker hat das Abo ' . $filter . ' abgelehnt - es gilt das bisherige Verhalten.');
        return 1;
    }
    $z['verbunden'] = time();
    bw_sonne_merken($id, $z);
    $offen = array();
    $ping = time();
    $id_pruef = 0;
    $cfg_pruef = time();
    $stamm = bw_sonne_stamm() . '/';
    while (time() - $start < $dauer) {
        if (time() - $id_pruef >= 1) {
            $id_pruef = time();
            if (!bw_sonne_hoerer_gilt($id)) {
                break;
            }
        }
        if (time() - $cfg_pruef >= 60) {
            $cfg_pruef = time();
            $cc = bw_config(false);
            if (empty($cc['sonne_ein'])) {
                break;
            }
        }
        if (time() - $ping >= 30) {
            $ping = time();
            if (@fwrite($s, chr(0xC0) . chr(0)) === false) {
                $z['grund'] = 'GETRENNT';
                break;
            }
        }
        $r = array($s);
        $w = null;
        $x = null;
        $n = @stream_select($r, $w, $x, 1);
        if ($n === false) {
            $z['grund'] = 'GETRENNT';
            break;
        }
        if ($n === 0) {
            continue;
        }
        $pk = $paket();
        if ($pk === null) {
            $z['grund'] = 'GETRENNT';
            break;
        }
        if (($pk[0] >> 4) !== 3 || strlen($pk[1]) < 2) {
            continue;
        }
        $tl = unpack('n', substr($pk[1], 0, 2));
        $t = (string) substr($pk[1], 2, $tl[1]);
        $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
        $wert = (string) substr($pk[1], $versatz);
        if (strncmp($t, $stamm, strlen($stamm)) !== 0 || strlen($wert) > 512) {
            continue;
        }
        $z['nachrichten']++;
        if ($t === $stamm . 'ts') {
            $satz = bw_sonne_satz_bauen($offen, $wert, microtime(true));
            $offen = array();
            if ($satz !== null) {
                $z['saetze']++;
                bw_sonne_merken($id, $z, $satz);
            } else {
                bw_log_wenn_neu('sonne_form', 'Sonne-1: ein Satz der Fensterbilanz trug keinen brauchbaren '
                    . 'Zeitstempel (' . bw_kurz($wert) . ') und wurde verworfen.');
            }
        } elseif (count($offen) < 400) {
            $offen[$t] = array($wert, microtime(true));
        }
    }
    @fwrite($s, chr(0xE0) . chr(0));
    fclose($s);
    $z['ende'] = time();
    if ($z['grund'] === 'GETRENNT') {
        bw_log_wenn_neu('sonne_hoerer_getrennt',
            'Sonne-1: die Verbindung zum MQTT-Broker riss ab - der naechste Takt hoert neu mit.');
    }
    bw_sonne_merken($id, $z);
    return $z['grund'] === '' ? 0 : 1;
}

/** Eine Gradzahl fuer die Oberflaeche - eine Nachkommastelle, Punkt. */
function bw_sonne_zahl($z)
{
    return $z === null ? '-' : number_format((float) $z, 1, '.', '');
}

/**
 * Die Zeilen im Reiter Test (Klartext, uebersetzt): array(array(titel,
 * 0|1|2, text), ...). Dieselbe Rechnung wie der Takt. Ein Kreuz, wenn kein
 * frischer Satz da ist - dann drueckt der Takt wie bisher, und das steht
 * dabei; ein Strich, solange noch nichts zu erwarten war.
 */
function bw_sonne_pruefzeilen(array $c, $jetzt = null)
{
    $titel = bw_t('TEXT.S_SONNE');
    if (empty($c['sonne_ein'])) {
        return array(array($titel, 2, bw_t('TEXT.S_SONNE_AUS')));
    }
    $jetzt = ($jetzt === null) ? time() : (int) $jetzt;
    $d = bw_sonne_lesen();
    $e = bw_sonne_bewerten($d, $jetzt);
    $h = (isset($d['hoerer']) && is_array($d['hoerer'])) ? $d['hoerer'] : array();
    $hg = (isset($h['grund']) && is_string($h['grund'])) ? $h['grund'] : '';
    $htext = '';
    if (in_array($hg, array('KEIN_BROKER', 'STUMM', 'ANMELDUNG', 'ABGELEHNT', 'GETRENNT'), true)) {
        $htext = sprintf(bw_t('TEXT.SONNE_H_' . $hg), isset($h['code']) ? (int) $h['code'] : 0);
    }
    $zeilen = array();
    if ($e['lage'] === 'frisch') {
        $fa = array();
        foreach ($e['wirkt'] as $az => $w) {
            $fa[] = $az . ' ' . bw_t($w === 1 ? 'TEXT.SONNE_JA' : 'TEXT.SONNE_NEIN');
        }
        $zeilen[] = array($titel, 1, sprintf(bw_t('TEXT.S_SONNE_GUT'), bw_zeitpunkt($e['ts']),
            max(0, (int) $e['alter']), bw_sonne_zahl($e['azimut']), bw_sonne_zahl($e['elevation']),
            $fa ? implode(', ', $fa) : '-'));
    } elseif ($e['lage'] === 'nie') {
        $verbunden = isset($h['verbunden']) ? (int) $h['verbunden'] : 0;
        if ($htext !== '') {
            $zeilen[] = array($titel, 0, sprintf(bw_t('TEXT.S_SONNE_HOERER'), $htext));
        } elseif ($verbunden > 0 && $jetzt - $verbunden > 360) {
            $zeilen[] = array($titel, 0, sprintf(bw_t('TEXT.S_SONNE_STILL'), bw_zeitpunkt($verbunden)));
        } elseif ($verbunden > 0) {
            $zeilen[] = array($titel, 2, sprintf(bw_t('TEXT.S_SONNE_WARTET'), bw_zeitpunkt($verbunden)));
        } else {
            $zeilen[] = array($titel, 2, bw_t('TEXT.S_SONNE_NIE'));
        }
    } else {
        $zeilen[] = array($titel, 0, sprintf(bw_t($e['lage'] === 'zukunft' ? 'TEXT.S_SONNE_ZUKUNFT' : 'TEXT.S_SONNE_ALT'),
            bw_zeitpunkt($e['ts']), abs((int) $e['alter']), (int) round(bw_sonne_frist() / 60))
            . ($htext !== '' ? ' - ' . $htext : ''));
    }
    $zt = bw_t('TEXT.S_SONNE_ZIELE');
    $ziele = bw_ziele($c);
    if (!$ziele) {
        $zeilen[] = array($zt, 2, bw_t('TEXT.SONNE_KEIN_ZIEL'));
        return $zeilen;
    }
    $teile = array();
    $zugeordnet = 0;
    $fremd = array();
    foreach (bw_sonne_ziele($c, $ziele, $e) as $u) {
        if (!$u['fassaden']) {
            $teile[] = sprintf(bw_t('TEXT.SONNE_Z_FREI'), $u['nr']);
            continue;
        }
        $zugeordnet++;
        $teile[] = sprintf(bw_t('TEXT.SONNE_Z_' . strtoupper($u['urteil'])), $u['nr'], implode(',', $u['fassaden']));
        if ($e['fassaden'] !== null) {
            foreach ($u['fassaden'] as $az) {
                if (!in_array($az, $e['fassaden'], true) && !in_array($az, $fremd, true)) {
                    $fremd[] = $az;
                }
            }
        }
    }
    if ($zugeordnet === 0) {
        $zeilen[] = array($zt, 2, bw_t('TEXT.SONNE_KEINE_ZUORDNUNG'));
        return $zeilen;
    }
    $text = implode(' | ', $teile);
    if ($fremd) {
        sort($fremd);
        $text = sprintf(bw_t('TEXT.SONNE_FREMD'), implode(', ', $fremd)) . ' ' . $text;
    }
    $zeilen[] = array($zt, $fremd ? 0 : 1, $text);
    return $zeilen;
}
