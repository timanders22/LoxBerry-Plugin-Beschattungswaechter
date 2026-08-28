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
 * LoxBerry-Wurzel IST: es traegt config/plugins UND data/plugins. Eine feste
 * Zahl ".." waere nur die naechste Wette (installiert liegt diese Datei drei
 * Ebenen unter der Wurzel, im entpackten Archiv zwei), und ein
 * ausgeschriebener Systempfad ist ein harter Pfad - den beanstandet der
 * Pluginpruefer beim Einspielen zu Recht.
 */
function bw_wurzel_suchen()
{
    $v = __DIR__;
    for ($i = 0; $i < 8 && $v !== '' && $v !== dirname($v); $i++) {
        if (is_dir($v . '/config/plugins') && is_dir($v . '/data/plugins')) {
            return $v;
        }
        $v = dirname($v);
    }
    return '';
}

/** Pfade des Plugins. LBP*-Umgebungsvariablen setzt LoxBerry. */
function bw_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $ordner = getenv('LBPPLUGINDIR');
    if ($ordner === false || $ordner === '') {
        /* Der Ordnername ist die LETZTE Stufe des Pfades - sowohl
           webfrontend/html/plugins/<ordner>/ als auch bin/plugins/<ordner>/
           enden darauf. Eine Stufe zu weit oben ergaebe "plugins". */
        $ordner = basename(__DIR__);
    }
    if ($ordner === '' || $ordner === '.' || $ordner === '/'
        || $ordner === 'html' || $ordner === 'htmlauth' || $ordner === 'plugins'
        || $ordner === 'bin') {
        $ordner = 'beschattungswaechter';
    }
    $lb = getenv('LBHOMEDIR');
    if ($lb === false || $lb === '') {
        $lb = bw_wurzel_suchen();
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
    );
    $p['cfgdatei'] = $p['config'] . '/beschattung.json';
    /* Die Zweitschrift liegt NEBEN dem Konfigordner, nicht darin: der
       Installer entfernt config/plugins/<ordner>/ bei jedem Upgrade und bei
       der Deinstallation. Eine Sicherung im Ordner straebe also genau in dem
       Fall mit, fuer den es sie gibt. */
    $p['sicherung'] = $lb . '/config/plugins/' . $ordner . '.backup.json';
    $p['logdatei'] = $p['log'] . '/beschattung.log';
    return $p;
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
    );
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
    /* Die durchnummerierten Ziele werden wie das erste geprueft. */
    if (preg_match('/^uuid[2-6]$/', $schluessel)) { $schluessel = 'uuid'; }
    if (preg_match('/^befehl[2-6]$/', $schluessel)) { $schluessel = 'befehl'; }
    switch ($schluessel) {
        case 'aktiv':
        case 'mqtt_ein':
        case 'pruefen_ein':
            return $s === '0' || $s === '1';
        case 'ms':
            return preg_match('/^[0-9]{1,3}$/', $s) === 1;
        case 'ms_nr':
            return $s === '' || preg_match('/^[0-9]{1,3}$/', $s) === 1;
        case 'aktionstoken':
            /* Leer heisst "abgeschaltet", und das ist erlaubt. Sonst genau die
               Form, die bw_token_neu() erzeugt. */
            return $s === '' || preg_match('/^[0-9a-f]{16,64}$/', $s) === 1;
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
        case 'von':
        case 'bis':
            return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $s) === 1;
        case 'abstand':
            return preg_match('/^[0-9]{1,4}$/', $s) === 1 && (int) $s >= 5 && (int) $s <= 720;
        case 'timeout':
            return preg_match('/^[0-9]{1,2}$/', $s) === 1 && (int) $s >= 2 && (int) $s <= 30;
    }
    return false;
}

/** Einen Wert fuer eine Meldung kurz und ungefaehrlich machen. */
function bw_kurz($w)
{
    if (is_array($w)) {
        return 'Liste mit ' . count($w) . ' Eintraegen';
    }
    if (is_object($w)) {
        return 'Objekt';
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
function bw_config_lage($setzen = null)
{
    static $l = array('lage' => 'unbekannt', 'fehlend' => array(), 'verworfen' => array());
    if ($setzen !== null) {
        $l = $setzen;
    }
    return $l;
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

    if (is_file($p['cfgdatei'])) {
        $roh = (string) @file_get_contents($p['cfgdatei']);
        $d = json_decode($roh, true);
        if (!is_array($d)) {
            $lage = 'kaputt';
            $d = null;
            if ($erzeugen) {
                @rename($p['cfgdatei'], $p['cfgdatei'] . '.kaputt');
                bw_log_wenn_neu('kaputt',
                    'Die Konfiguration war unlesbar und liegt jetzt als beschattung.json.kaputt daneben.');
            }
            $zs = is_file($p['sicherung'])
                ? json_decode((string) @file_get_contents($p['sicherung']), true) : null;
            if (is_array($zs) && $zs) {
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

    bw_config_lage(array('lage' => $lage, 'fehlend' => $fehlend, 'verworfen' => $verworfen));
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
    if (!is_file($p['logdatei'])) {
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
    $alle = bw_miniserver();
    if (!$alle) {
        return array('ok' => false, 'code' => 0,
                     'text' => 'kein Miniserver in der LoxBerry-Konfiguration', 'url' => '');
    }
    $m = bw_miniserver_gewaehlt($c, $alle);
    $uuid = bw_kennung_sauber($uuid === null ? (isset($c['uuid']) ? $c['uuid'] : '') : $uuid);
    $befehl = bw_kennung_sauber($befehl === null ? (isset($c['befehl']) ? $c['befehl'] : '') : $befehl);
    if ($uuid === '' || $befehl === '') {
        return array('ok' => false, 'code' => 0,
                     'text' => 'Kennung oder Befehl unbrauchbar', 'url' => '');
    }
    /* Der Trockenlauf geht durch DENSELBEN Weg - alle Wachen greifen echt,
       nur das Senden unterbleibt. Eine zweite Funktion, die den Vorgang
       beschreibt, liefe mit dem Sendecode auseinander, und dann zeigte die
       Vorschau etwas anderes an, als der Ernstfall tut. */
    if (bw_trocken()) {
        return array('ok' => true, 'code' => 0, 'probe' => true,
                     'text' => 'PROBE - es wurde NICHTS gesendet',
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
        curl_close($ch);
        if ($antwort === false && $code === 0) {
            return array('ok' => false, 'code' => 0,
                         'text' => $fehler !== '' ? $fehler : 'keine Antwort', 'url' => $url);
        }
    } else {
        $vorher = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string) $frist);
        $ctx = stream_context_create(array('http' => array(
            'method'          => 'GET',
            'header'          => implode("\r\n", $kopf) . "\r\n",
            'timeout'         => $frist,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 1,
            'user_agent'      => 'LoxBerry Beschattungswaechter',
        )));
        $antwort = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header) && is_array($http_response_header)) {
            /* Bei einer Weiterleitung stehen mehrere Statuszeilen darin; es
               gilt die letzte. */
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $mm)) {
                    $code = (int) $mm[1];
                }
            }
        }
        @ini_set('default_socket_timeout', (string) $vorher);
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
function bw_fassung()
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
 * ZUM AKTIONSTOKEN: dieses Plugin hat keinen. Es fuehrt keinen Endpunkt im
 * unangemeldeten Bereich, und die Zugangsdaten des Miniservers stehen in der
 * zentralen LoxBerry-Konfiguration, nicht hier. Die Datei traegt also
 * Einstellungen und kein Geheimnis - und der Hinweis am Knopf sagt genau
 * das. Bis 0.9.10 behauptete er das Gegenteil.
 */
function bw_sicherung_bauen()
{
    $kopf = array(
        '_hinweis' => 'Sicherung der Einstellungen des LoxBerry-Plugins Beschattungswaechter. '
                    . 'Zum Zurueckspielen im Reiter Einstellungen. Sie enthaelt KEINE '
                    . 'Zugangsdaten: das Kennwort des Miniservers steht in der zentralen '
                    . 'LoxBerry-Konfiguration und wird von diesem Plugin weder angezeigt '
                    . 'noch gespeichert.',
        '_plugin'  => 'beschattungswaechter',
        '_fassung' => bw_fassung(),
        '_stand'   => date('Y-m-d H:i:s'),
    );
    return array_merge($kopf, bw_config());
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
 * Eine Sicherung aus 0.9.9 oder 0.9.10 kennt ms_nr noch nicht; der Schluessel
 * fehlt dann und behaelt seine Vorgabe - alte Dateien bleiben lesbar.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function bw_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(bw_t('TEXT.SICH_KEIN_JSON')), 0);
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
            continue;
        }
        if (!bw_wert_pruefen($k, $w)) {
            $mangel[] = sprintf(bw_t('TEXT.SICH_WERT'), bw_e($k), bw_e(bw_kurz($w)));
            continue;
        }
        $neu[$k] = is_string($w) ? trim($w) : $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = bw_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
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
    }
    $kand[] = dirname(dirname(dirname(__DIR__))) . '/templates/plugins/' . basename(__DIR__);
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
     * Fenster, in dem das Merkwort fuer alle lesbar ist. */
    $tmp = $datei . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
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
 * Spalten: bez (Sprachschluessel), einheit, min, max, zeile (0 = nur ueber
 * MQTT und aktion=json).
 *
 * NEUE FELDER WERDEN HINTEN ANGEHAENGT. Loxone sucht den Suchtext woertlich
 * und nimmt den ERSTEN Treffer der Zeile; die Reihenfolge ist damit Teil der
 * Zusage an bestehende Anlagen.
 * ================================================================== */
function bw_felder()
{
    return array(
        'OK'       => array('bez' => 'FELD.OK',       'einheit' => '',  'min' => 0,  'max' => 1,      'zeile' => 1),
        'AKTIV'    => array('bez' => 'FELD.AKTIV',    'einheit' => '',  'min' => 0,  'max' => 1,      'zeile' => 1),
        'FENSTER'  => array('bez' => 'FELD.FENSTER',  'einheit' => '',  'min' => 0,  'max' => 1,      'zeile' => 1),
        'ZIELE'    => array('bez' => 'FELD.ZIELE',    'einheit' => '',  'min' => 0,  'max' => 6,      'zeile' => 1),
        'GESENDET' => array('bez' => 'FELD.GESENDET', 'einheit' => '',  'min' => 0,  'max' => 999999, 'zeile' => 1),
        'FEHLER'   => array('bez' => 'FELD.FEHLER',   'einheit' => '',  'min' => 0,  'max' => 9999,   'zeile' => 1),
        'CODE'     => array('bez' => 'FELD.CODE',     'einheit' => '',  'min' => 0,  'max' => 599,    'zeile' => 1),
        'ALTER'    => array('bez' => 'FELD.ALTER',    'einheit' => 's', 'min' => -1, 'max' => 999999, 'zeile' => 1),
        'ZAEHLER'  => array('bez' => 'FELD.ZAEHLER',  'einheit' => '',  'min' => -1, 'max' => 999,    'zeile' => 1),
        'SCHARF'   => array('bez' => 'FELD.SCHARF',   'einheit' => '',  'min' => -1, 'max' => 99,     'zeile' => 1),
        'AUTOMATIKEN' => array('bez' => 'FELD.AUTOMATIKEN', 'einheit' => '', 'min' => -1, 'max' => 99, 'zeile' => 1),
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
    return array(
        'OK'          => !empty($lauf['ok']) ? 1 : 0,
        'AKTIV'       => empty($c['aktiv']) ? 0 : 1,
        'FENSTER'     => bw_im_fenster($c) ? 1 : 0,
        'ZIELE'       => count(bw_ziele($c)),
        'GESENDET'    => isset($stand['gesendet']) ? (int) $stand['gesendet'] : 0,
        'FEHLER'      => isset($stand['fehler']) ? (int) $stand['fehler'] : 0,
        'CODE'        => isset($stand['code']) ? (int) $stand['code'] : 0,
        'ALTER'       => $letzte > 0 ? (time() - $letzte) : -1,
        'ZAEHLER'     => isset($lauf['zaehler']) ? (int) $lauf['zaehler'] : -1,
        'SCHARF'      => isset($stand['scharf']) ? (int) $stand['scharf'] : -1,
        'AUTOMATIKEN' => isset($stand['automatiken']) ? (int) $stand['automatiken'] : -1,
    );
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

function bw_lauf_schreiben($ok)
{
    $p = bw_paths();
    $alt = bw_lauf_lesen();
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
    $zeilen = array();
    foreach (bw_werte($c, $stand) as $k => $v) {
        /* ALTER geht NICHT ueber MQTT: es aendert sich jede Sekunde und
           machte jeden Doppelt-senden-Filter wirkungslos. Der Zeitstempel
           unten sagt dasselbe, und der Miniserver rechnet selbst. */
        if ($k === 'ALTER') { continue; }
        $zeilen[] = 'publish ' . $w . '/' . strtolower($k) . ' ' . bw_mqtt_wert($v);
    }
    $lauf = bw_lauf_lesen();
    $zeilen[] = 'publish ' . $w . '/status/ts ' . (int) $lauf['ts'];
    $zeilen[] = 'publish ' . $w . '/status/zaehler ' . (int) $lauf['zaehler'];
    $zeilen[] = 'publish ' . $w . '/status/ok ' . (int) $lauf['ok'];
    return bw_mqtt_senden($gw['udpport'], $zeilen);
}

/** NUR die drei Lebenszeichen - fuer einen Lauf, der sonst nichts zu sagen hat. */
function bw_mqtt_lebenszeichen(?array $c = null)
{
    if ($c === null) { $c = bw_config(); }
    if (empty($c['mqtt_ein'])) { return 0; }
    $gw = bw_mqtt_gateway_info();
    if ($gw === null || $gw['udpport'] === 0) { return 0; }
    $w = bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '');
    $lauf = bw_lauf_lesen();
    return bw_mqtt_senden($gw['udpport'], array(
        'publish ' . $w . '/status/ts ' . (int) $lauf['ts'],
        'publish ' . $w . '/status/zaehler ' . (int) $lauf['zaehler'],
        'publish ' . $w . '/status/ok ' . (int) $lauf['ok'],
    ));
}

/** Die Themen, die dieses Plugin veroeffentlicht - fuer die Tabelle im Reiter. */
function bw_mqtt_themen(?array $c = null)
{
    if ($c === null) { $c = bw_config(); }
    $w = bw_mqtt_praefix(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '');
    $aus = array();
    foreach (bw_felder() as $k => $i) {
        if ($k === 'ALTER') { continue; }
        $aus[$w . '/' . strtolower($k)] = $i['bez'];
    }
    $aus[$w . '/status/ts'] = 'FELD.TS';
    $aus[$w . '/status/zaehler'] = 'FELD.ZAEHLER';
    $aus[$w . '/status/ok'] = 'FELD.LEBT';
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
    $m = bw_miniserver_gewaehlt($c);
    if ($m === null) {
        return array(0, bw_t('TEXT.KEIN_MS'), array());
    }
    $url = 'http://' . $m['adresse'] . ':' . $m['port'] . '/data/LoxAPP3.json';
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
    return array(1, '', $d);
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
            'raum'  => isset($b['room'], $raeume[$b['room']]) ? $raeume[$b['room']] : '',
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
        curl_close($ch);
        return array($code, $a === false ? null : $a, $f);
    }
    $vorher = ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', (string) $frist);
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => implode("\r\n", $kopf) . "\r\n",
        'timeout' => $frist, 'ignore_errors' => true,
        'follow_location' => 0, 'max_redirects' => 1,
        'user_agent' => 'LoxBerry Beschattungswaechter')));
    $a = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $t)) { $code = (int) $t[1]; }
        }
    }
    @ini_set('default_socket_timeout', (string) $vorher);
    return array($code, $a === false ? null : $a, $code === 0 ? 'keine Antwort' : '');
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
    if (isset($stand['scharf']) && (int) $stand['scharf'] >= 0) {
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
    /* notify_ext() steckt in einer Bibliothek, die nicht jede
       LoxBerry-Fassung gleich bestueckt - und ein @ hilft gegen
       "undefined function" nicht. */
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
        $adresse = '/dev/udp/0.0.0.0/1';   /* wird unten ersetzt */
        $adresse = $host;
        $cmds = array();
        $cmds[] = array(
            'title'   => 'BW Befehl jetzt senden',
            'comment' => bw_t('VORLAGE.C_JETZT'),
            'on'      => bw_endpunkt_pfad(array('token' => $token, 'aktion' => 'jetzt'), true),
            'off'     => '',
        );
        if (!empty($c['pruefen_ein'])) {
            $cmds[] = array(
                'title'   => 'BW Automatiken zaehlen',
                'comment' => bw_t('VORLAGE.C_PRUEFEN'),
                'on'      => bw_endpunkt_pfad(array('token' => $token, 'aktion' => 'pruefen'), true),
                'off'     => '',
            );
        }
        return array('VQ_Beschattungswaechter.xml', bw_xml_virtual_out(array(
            'title'   => 'Beschattungswaechter Befehle',
            'comment' => 'Beschattungswaechter (LoxBerry-Plugin)',
            'hint'    => $hinweis,
            'address' => $adresse,
        ), $cmds));
    }
    $cmds = array();
    foreach (bw_felder() as $name => $i) {
        if (empty($i['zeile'])) { continue; }
        $cmds[] = array(
            'title'   => 'BW ' . $name,
            'comment' => bw_t($i['bez']),
            'check'   => bw_check($name),
            'einheit' => $i['einheit'],
            'min'     => $i['min'],
            'max'     => $i['max'],
        );
    }
    return array('VI_Beschattungswaechter.xml', bw_xml_virtual_in_http(array(
        'title'   => 'Beschattungswaechter',
        'comment' => 'Beschattungswaechter (LoxBerry-Plugin)',
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
 * Aufrufer nicht zur Verfuegung (Hausform, REGELN_2). */
function bw_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
