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

/** Pfade des Plugins. LBP*-Umgebungsvariablen setzt LoxBerry. */
function bw_pfade()
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
        $lb = is_dir('/opt/loxberry') ? '/opt/loxberry' : '';
    }
    $cfg = getenv('LBPCONFIGDIR');
    $log = getenv('LBPLOGDIR');
    $dat = getenv('LBPDATADIR');
    $p = array(
        'plugin' => $ordner,
        'lbhome' => $lb,
        'config' => ($cfg !== false && $cfg !== '') ? $cfg : $lb . '/config/plugins/' . $ordner,
        'log'    => ($log !== false && $log !== '') ? $log : $lb . '/log/plugins/' . $ordner,
        'data'   => ($dat !== false && $dat !== '') ? $dat : $lb . '/data/plugins/' . $ordner,
    );
    $p['cfgdatei'] = $p['config'] . '/beschattung.json';
    $p['sicherung'] = $lb . '/config/plugins/' . $ordner . '.backup.json';
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
 * Projektdatei. Solange sie fehlt, sendet bw_senden() nicht (Pruefung auf
 * leer weiter unten), und die Oberflaeche nimmt das Speichern nicht an.
 *
 * 'abstand' 60 Minuten ist eine Abwaegung, keine technische Grenze: der
 * Befehl holt auch eine von Hand abgeschaltete Automatik zurueck. Wer einen
 * Rollladen in der Sonne hochfaehrt, hat ihn sonst nach kurzer Zeit wieder
 * unten. Eine Stunde ist lang genug, dass das nicht stoert, und kurz genug,
 * dass eine Fassade, deren Sonne mittags kommt, nicht den Tag verpasst.
 */
function bw_vorgaben()
{
    return array(
        'aktiv'      => 0,
        'ms'         => 0,
        'uuid'       => '',
        'befehl'     => 'auto',
        'von'        => '06:00',
        'bis'        => '21:00',
        'abstand'    => 60,
        'timeout'    => 5,
    );
}

function bw_config()
{
    $p = bw_pfade();
    $c = bw_vorgaben();
    if (is_file($p['cfgdatei'])) {
        $d = json_decode((string) @file_get_contents($p['cfgdatei']), true);
        if (is_array($d)) {
            foreach ($c as $k => $v) {
                if (array_key_exists($k, $d)) {
                    $c[$k] = $d[$k];
                }
            }
        }
    }
    $c['abstand'] = max(5, min(720, (int) $c['abstand']));
    $c['timeout'] = max(2, min(30, (int) $c['timeout']));
    return $c;
}

function bw_config_speichern(array $c)
{
    $p = bw_pfade();
    if (!is_dir($p['config'])) {
        @mkdir($p['config'], 0775, true);
    }
    /* Erst in eine Nebendatei, dann umbenennen - ein Stromausfall mitten im
       Schreiben hinterlaesst sonst eine halbe Datei. */
    $tmp = $p['cfgdatei'] . '.tmp';
    $js = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $js) === false) {
        return false;
    }
    @rename($tmp, $p['cfgdatei']);
    @copy($p['cfgdatei'], $p['sicherung']);
    return true;
}

function bw_log($text)
{
    $p = bw_pfade();
    if (!is_dir($p['log'])) {
        @mkdir($p['log'], 0775, true);
    }
    $f = $p['log'] . '/beschattung.log';
    if (is_file($f) && filesize($f) > 262144) {
        $z = file($f, FILE_IGNORE_NEW_LINES) ?: array();
        @file_put_contents($f, implode("\n", array_slice($z, -800)) . "\n");
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $text . "\n", FILE_APPEND);
}

/**
 * Die Miniserver aus der zentralen LoxBerry-Konfiguration.
 *
 * Damit steht das Passwort NICHT in diesem Plugin und auch nicht in der
 * Loxone-Projektdatei - es bleibt dort, wo LoxBerry es ohnehin fuehrt.
 * Dieselbe Lesart wie im BLE-Scanner dieses Hauses.
 */
function bw_miniserver()
{
    $p = bw_pfade();
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

/** Kennung pruefen: Loxone-UUID oder ein Bausteinname ohne Sonderzeichen. */
function bw_kennung_sauber($s)
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $s)) {
        return '';
    }
    return $s;
}

/**
 * Den Befehl an den Miniserver schicken.
 *
 * Rueckgabe: array(ok, code, text, url)
 */
function bw_senden(array $c)
{
    $alle = bw_miniserver();
    if (!$alle) {
        return array('ok' => false, 'code' => 0, 'text' => 'kein Miniserver in der LoxBerry-Konfiguration', 'url' => '');
    }
    $nr = (int) $c['ms'];
    $m = isset($alle[$nr]) ? $alle[$nr] : $alle[0];
    $uuid = bw_kennung_sauber($c['uuid']);
    $befehl = bw_kennung_sauber($c['befehl']);
    if ($uuid === '' || $befehl === '') {
        return array('ok' => false, 'code' => 0, 'text' => 'Kennung oder Befehl unbrauchbar', 'url' => '');
    }
    $url = 'http://' . $m['adresse'] . ':' . $m['port'] . '/dev/sps/io/'
         . rawurlencode($uuid) . '/' . rawurlencode($befehl);
    $kopf = "Accept: */*\r\n";
    if ($m['user'] !== '') {
        $kopf .= 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']) . "\r\n";
    }
    $ctx = stream_context_create(array('http' => array(
        'method'        => 'GET',
        'header'        => $kopf,
        'timeout'       => (int) $c['timeout'],
        'ignore_errors' => true,
        'user_agent'    => 'LoxBerry Beschattungswaechter',
    )));
    $antwort = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0])
        && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $mm)) {
        $code = (int) $mm[1];
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
    $p = bw_pfade();
    $f = $p['data'] . '/stand.json';
    if (!is_file($f)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

function bw_stand_schreiben(array $s)
{
    $p = bw_pfade();
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    $f = $p['data'] . '/stand.json';
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        @rename($tmp, $f);
    }
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
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(bw_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = bw_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

function bw_sprachdatei()
{
    $t = getenv('LBPTEMPLATEDIR');
    if ($t === false || $t === '') {
        $lb = getenv('LBHOMEDIR');
        $kand = array();
        if ($lb !== false && $lb !== '') {
            $kand[] = rtrim($lb, '/\\') . '/templates/plugins/' . basename(__DIR__);
        }
        $kand[] = dirname(dirname(dirname(__DIR__))) . '/templates/plugins/' . basename(__DIR__);
        $kand[] = dirname(dirname(__DIR__)) . '/templates';
        $t = $kand[count($kand) - 1];
        foreach ($kand as $k) {
            if (is_dir($k . '/lang')) { $t = $k; break; }
        }
    }
    $lang = 'de';
    $g = (getenv('LBHOMEDIR') ?: '/opt/loxberry') . '/config/system/general.json';
    if (is_file($g)) {
        $d = json_decode((string) @file_get_contents($g), true);
        if (isset($d['Base']['Lang']) && $d['Base']['Lang'] === 'en') { $lang = 'en'; }
    }
    $f = $t . '/lang/language_' . $lang . '.ini';
    return is_file($f) ? $f : $t . '/lang/language_de.ini';
}

function bw_t($schluessel)
{
    static $tab = null;
    if ($tab === null) {
        $tab = @parse_ini_file(bw_sprachdatei(), true);
        if (!is_array($tab)) { $tab = array(); }
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
    $pfade = bw_pfade();
    $verz  = isset($pfade['data']) ? $pfade['data'] : '';
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
    $tmp = $datei . '.tmp';
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
