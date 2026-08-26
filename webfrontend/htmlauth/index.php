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
if ($bw_geladen === '' || !function_exists('bw_pfade')) {
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
   class_exists() ist dann immer falsch, und die Seite erscheint ohne Menue. */
$bw_lb = getenv('LBHOMEDIR');
if ($bw_lb === false || $bw_lb === '') {
    $bw_lb = is_dir('/opt/loxberry') ? '/opt/loxberry' : '';
}
if ($bw_lb !== '' && is_file($bw_lb . '/libs/phplib/loxberry_system.php')) {
    require_once $bw_lb . '/libs/phplib/loxberry_system.php';
    if (is_file($bw_lb . '/libs/phplib/loxberry_web.php')) {
        require_once $bw_lb . '/libs/phplib/loxberry_web.php';
    }
}

/* ---------- Sprache ------------------------------------------------------ */


function bw_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- Reiter: Positivliste, ids und Leiste gehoeren zusammen -------- */
$bw_reiter = array('tab-settings', 'tab-test', 'tab-log');
$bw_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $bw_reiter, true)) {
    $bw_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && in_array('tab-' . $_GET['form'], $bw_reiter, true)) {
    $bw_tab = 'tab-' . $_GET['form'];
}

/* ---------- Eingaben verarbeiten ----------------------------------------- */
$bw_cfg = bw_config();
$bw_meldung = '';
$bw_fehler = '';
$bw_probe = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['speichern'])) {
    $u = bw_kennung_sauber(isset($_POST['uuid']) ? $_POST['uuid'] : '');
    $b = bw_kennung_sauber(isset($_POST['befehl']) ? $_POST['befehl'] : '');
    if ($u === '' || $b === '') {
        $bw_fehler = bw_t('TEXT.KENNUNG_UNGUELTIG');
    } else {
        $bw_cfg['aktiv'] = empty($_POST['aktiv']) ? 0 : 1;
        $bw_cfg['ms'] = (int) (isset($_POST['ms']) ? $_POST['ms'] : 0);
        $bw_cfg['uuid'] = $u;
        $bw_cfg['befehl'] = $b;
        foreach (array('von', 'bis') as $k) {
            $v = trim((string) (isset($_POST[$k]) ? $_POST[$k] : ''));
            if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $v)) {
                $bw_cfg[$k] = $v;
            }
        }
        $bw_cfg['abstand'] = max(5, min(720, (int) (isset($_POST['abstand']) ? $_POST['abstand'] : 60)));
        bw_config_speichern($bw_cfg);
        $bw_meldung = bw_t('TEXT.GESPEICHERT');
        $bw_cfg = bw_config();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jetzt'])) {
    $bw_probe = bw_senden($bw_cfg);
    $st = bw_stand_lesen();
    $st['letzte'] = time();
    if ($bw_probe['ok']) { $st['letzte_ok'] = time(); }
    bw_stand_schreiben($st);
    bw_log('von Hand ausgeloest: ' . ($bw_probe['ok'] ? 'HTTP ' . $bw_probe['code']
           : 'FEHLER HTTP ' . $bw_probe['code'] . ' ' . $bw_probe['text']));
}

$bw_stand = bw_stand_lesen();
$bw_ms = bw_miniserver();
$bw_rahmen = class_exists('LBWeb', false);

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bw_sichern'])) {
    $bw_js = json_encode(bw_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($bw_js !== false) {
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
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
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

<?php if ($bw_meldung !== '') { ?><div class="sm-hinweis"><?= bw_e($bw_meldung) ?></div><?php } ?>
<?php if ($bw_fehler !== '') { ?><div class="sm-fehler"><?= bw_e($bw_fehler) ?></div><?php } ?>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN da, nicht in einer Schleife
     erzeugt. Umgeschaltet wird ueber den Server, damit jeder Reiter
     verlinkbar und die Seite ohne Skript bedienbar bleibt. Ob Leiste,
     Bereiche und Positivliste dieselben Namen fuehren, zaehlt der Reiter
     Test nach. -->
<div class="sm-tabs">
  <a class="sm-tab<?= $bw_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
     href="index.php?form=settings"><?php echo bw_t('REITER.EINSTELLUNGEN'); ?></a>
  <a class="sm-tab<?= $bw_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
     href="index.php?form=test"><?php echo bw_t('REITER.TEST'); ?></a>
  <a class="sm-tab<?= $bw_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
     href="index.php?form=log"><?php echo bw_t('REITER.PROTOKOLL'); ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<h2><?php echo bw_t('TEXT.H_EINSTELLUNGEN'); ?></h2>

<div class="sm-step"><?php echo bw_t('TEXT.WARUM'); ?></div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="aktiv" value="1"<?= !empty($bw_cfg['aktiv']) ? ' checked' : '' ?>>
    <?php echo bw_t('TEXT.L_AKTIV'); ?></label>
  <p class="sm-hilfe"><?php echo bw_t('TEXT.H_AKTIV'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo bw_t('TEXT.L_MS'); ?></label>
  <select data-role="none" name="ms">
<?php foreach ($bw_ms as $i => $m) { ?>
    <option value="<?= (int) $i ?>"<?= ((int) $bw_cfg['ms'] === (int) $i) ? ' selected' : '' ?>><?= bw_e($m['name'] . ' (' . $m['adresse'] . ')') ?></option>
<?php } ?>
<?php if (!$bw_ms) { ?><option value="0"><?php echo bw_t('TEXT.KEIN_MS'); ?></option><?php } ?>
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
<div class="sm-warnung"><?= bw_t('TEXT.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="bw_sichern" value="1"><?= bw_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="bw_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="bw_zurueck" value="1"><?= bw_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
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
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="jetzt" value="1"><?php echo bw_t('TEXT.JETZT'); ?></button>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bw_t('LEGENDE.AKTION'); ?></span></div>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_JETZT'); ?></p>
</form>

<?php if ($bw_probe !== null) { ?>
<table class="sm-tbl">
  <tr><th><?php echo bw_t('TEXT.ERGEBNIS'); ?></th><th>HTTP</th><th><?php echo bw_t('TEXT.ANTWORT'); ?></th></tr>
  <tr>
    <td><?= $bw_probe['ok'] ? '<span class="sm-an">' . bw_e(bw_t('TEXT.GESENDET')) . '</span>'
                            : '<span class="sm-aus">' . bw_e(bw_t('TEXT.FEHLGESCHLAGEN')) . '</span>' ?></td>
    <td class="sm-mono"><?= (int) $bw_probe['code'] ?></td>
    <td class="sm-mono"><?= bw_e(substr($bw_probe['text'], 0, 160)) ?></td>
  </tr>
</table>
<p class="sm-hilfe sm-mono"><?= bw_e($bw_probe['url']) ?></p>
<?php } ?>

<h3><?php echo bw_t('TEXT.H_SELBST'); ?></h3>
<?php
/* Gezaehlt wird in DIESER Datei, nicht in einer zweiten Liste daneben. */
$bw_selbst = array();
$bw_quelle = (string) @file_get_contents(__FILE__);
preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $bw_quelle, $bw_m1);
preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $bw_quelle, $bw_m2);
$bw_leiste = array_unique($bw_m1[1]); sort($bw_leiste);
$bw_flaechen = array_unique($bw_m2[1]); sort($bw_flaechen);
$bw_soll = $bw_reiter; sort($bw_soll);
$bw_selbst[] = array('was' => bw_t('TEXT.S_REITER'),
    'ok' => ($bw_leiste === $bw_flaechen && $bw_leiste === $bw_soll),
    'wie' => sprintf('%d / %d / %d', count($bw_leiste), count($bw_flaechen), count($bw_soll)));

/* VIER Stufen bis zur Wurzel: <ordner> -> plugins -> htmlauth ->
   webfrontend. Drei blieben bei webfrontend stehen und suchten darunter
   ein bin/ - das gibt es dort nicht. */
$bw_bin = dirname(dirname(dirname(dirname(__DIR__)))) . '/bin/plugins/'
        . bw_pfade()['plugin'] . '/bw_lauf.php';
$bw_binenv = getenv('LBPBINDIR');
if ($bw_binenv !== false && $bw_binenv !== '') { $bw_bin = $bw_binenv . '/bw_lauf.php'; }
$bw_selbst[] = array('was' => bw_t('TEXT.S_LAUF'), 'ok' => is_file($bw_bin),
    'wie' => is_file($bw_bin) ? $bw_bin : bw_t('TEXT.S_NICHT_GEFUNDEN'));

$bw_selbst[] = array('was' => bw_t('TEXT.S_MS'), 'ok' => (bool) $bw_ms,
    'wie' => $bw_ms ? count($bw_ms) . ' x' : bw_t('TEXT.KEIN_MS'));

/* Der Cron-Eintrag. Ohne ihn steht das Plugin da und tut nichts, und man
   sieht es an nichts - genau das ist am 24.08.2026 passiert: die 0.9.0 legte
   cron/cron.15min als ORDNER an statt als Datei, der Installer machte daraus
   einen Unterordner, und LoxBerry fuehrt in diesen Verzeichnissen nur Dateien
   aus. Eine Stunde lang kam kein einziger Lauf, und die Oberflaeche meldete
   nichts, weil sie gar nicht hinsah. */
$bw_cron = ($bw_lb !== '') ? $bw_lb . '/system/cron/cron.05min/' . bw_pfade()['plugin'] : '';
$bw_cron_ok = ($bw_cron !== '' && is_file($bw_cron));
$bw_selbst[] = array('was' => bw_t('TEXT.S_CRON'), 'ok' => $bw_cron_ok,
    'wie' => $bw_cron_ok ? $bw_cron : ($bw_cron === '' ? bw_t('TEXT.S_NICHT_GEFUNDEN')
             : (is_dir($bw_cron) ? bw_t('TEXT.S_CRON_ORDNER') : bw_t('TEXT.S_NICHT_GEFUNDEN'))));

/* Ein Rest aus der 0.9.0. Er schadet nicht, aber er gehoert weg - und man
   findet ihn sonst nie wieder. */
$bw_rest = ($bw_lb !== '') ? $bw_lb . '/system/cron/cron.15min/' . bw_pfade()['plugin'] : '';
if ($bw_rest !== '' && file_exists($bw_rest)) {
    $bw_selbst[] = array('was' => bw_t('TEXT.S_REST'), 'ok' => false, 'wie' => $bw_rest);
}

$bw_cfgd = bw_pfade()['cfgdatei'];
$bw_selbst[] = array('was' => bw_t('TEXT.S_SCHREIBBAR'),
    'ok' => is_writable(is_file($bw_cfgd) ? $bw_cfgd : dirname($bw_cfgd)), 'wie' => $bw_cfgd);
?>
<table class="sm-tbl">
  <tr><th><?php echo bw_t('TEXT.PRUEFPUNKT'); ?></th><th><?php echo bw_t('TEXT.ERGEBNIS'); ?></th><th><?php echo bw_t('TEXT.GEMESSEN'); ?></th></tr>
<?php foreach ($bw_selbst as $bw_z) { ?>
  <tr><td><?= bw_e($bw_z['was']) ?></td>
      <td><?= $bw_z['ok'] ? '<span class="sm-an">' . bw_e(bw_t('TEXT.S_OK')) . '</span>'
                          : '<span class="sm-aus">' . bw_e(bw_t('TEXT.S_NOK')) . '</span>' ?></td>
      <td class="sm-mono"><?= bw_e($bw_z['wie']) ?></td></tr>
<?php } ?>
</table>
</div>

<!-- ================= Protokoll ================= -->
<div class="sm-seite<?= $bw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo bw_t('TEXT.H_PROTOKOLL'); ?></h2>
<?php
$bw_logdatei = bw_pfade()['log'] . '/beschattung.log';
$bw_zeilen = is_file($bw_logdatei)
    ? array_slice(array_reverse(file($bw_logdatei, FILE_IGNORE_NEW_LINES) ?: array()), 0, 80)
    : array();
?>
<?php if ($bw_zeilen) { ?>
<div class="sm-log"><?= bw_e(implode("\n", $bw_zeilen)) ?></div>
<?php } else { ?>
<p class="sm-grau"><?php echo bw_t('TEXT.PROTOKOLL_LEER'); ?></p>
<?php } ?>
<p class="sm-hilfe"><?php echo bw_t('TEXT.H_PROTOKOLL_HILFE'); ?></p>
</div>

</div>
<?php
if ($bw_rahmen) {
    LBWeb::lbfooter();
}
