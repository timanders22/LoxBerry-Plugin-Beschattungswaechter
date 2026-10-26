<?php
/**
 * Beschattungswaechter - der Lauf
 *
 * Wird vom Cron alle FUENF Minuten aufgerufen (cron/cron.05min) und
 * entscheidet selbst, ob er etwas tut: nur wenn eingeschaltet, nur im
 * Zeitfenster und nur, wenn seit dem letzten Befehl der eingestellte Abstand
 * vergangen ist. Der Abstand steht in der Konfiguration und nicht im
 * Ordnernamen des Crons - sonst muesste man beim Aendern eine Datei
 * verschieben.
 *
 * (Bis 0.9.10 stand hier "alle fuenfzehn Minuten". Der Takt war seit 0.9.2
 * ein anderer; der Satz ist nicht mitgezogen worden - und dieselbe Zahl
 * stand noch an drei weiteren Stellen.)
 *
 * DAS LEBENSZEICHEN GEHT BEI JEDEM DURCHGANG HINAUS, auch wenn nichts
 * gesendet wurde. Sonst ist ein toter Waechter von einem ruhigen Haus nicht
 * zu unterscheiden: die virtuellen Eingaenge behalten ihren letzten Wert.
 *
 * Aufruf von Hand:
 *   php bw_lauf.php            regulaerer Lauf
 *   php bw_lauf.php --jetzt    ohne Ruecksicht auf Abstand und Fenster
 *   php bw_lauf.php --probe    sagt nur, was er taete - sendet nichts
 *   php bw_lauf.php --mqtt-leeren
 *                              leert die zurueckbehaltenen MQTT-Themen
 *                              (aus uninstall/uninstall)
 *   php bw_lauf.php --sonne-hoeren <kennung>
 *                              der Begleitprozess (seit 0.9.31 "Haus-Hoerer":
 *                              Sonnenstand der Fensterbilanz UND Anwesenheit) -
 *                              startet der Takt selbst (bw_sonne_hoerer_starten()),
 *                              nur mit dem Haken "Sonnenstand der Fensterbilanz
 *                              nutzen" oder "Wenn niemand zu Hause ist, das A
 *                              frueher druecken"; nicht von Hand aufrufen
 *
 * SEIT 0.9.31 (Gruppe 23): jeder Takt schickt ALLE Themen ueber MQTT (N1,
 * E-E9), nicht nur nach einem Befehl; im Zeitfenster wird alle 15 Minuten
 * gezaehlt, auch ohne Befehl (B23); der Waechter der Befehlsbruecke laeuft
 * hier im Fuenfminutentakt (E-E3).
 */

/* Welche Lage gilt, entscheidet der eigene Ablageort, nicht die Reihenfolge
   der Versuche: liegt diese Datei unter .../bin/plugins/<ordner>, ist sie
   installiert, sonst liegt sie in einem ausgepackten Archiv. Bis 0.9.19 kam
   der installierte Kandidat auch im Archiv VOR der eigenen Bibliothek - aus
   einem Archiv unter / hiess das /webfrontend/html/plugins/bin/bw_lib.php ab
   der Laufwerkswurzel, und was dort lag, lief als Bibliothek (in WSL
   gemessen, Pruefung-Beschattungswaechter-0.9.20, Fall T4; Bauart
   ZendureSolarFlow 0.9.26). */
$bw_lib = '';
$bw_kand = array(getenv('LBPHTMLDIR') ? getenv('LBPHTMLDIR') . '/bw_lib.php' : '');
if (basename(dirname(__DIR__)) === 'plugins') {
    $bw_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
               . basename(__DIR__) . '/bw_lib.php';
} else {
    $bw_kand[] = dirname(__DIR__) . '/webfrontend/html/bw_lib.php';
}
foreach ($bw_kand as $bw_k) {
    if ($bw_k !== '' && is_file($bw_k)) { $bw_lib = $bw_k; break; }
}
if ($bw_lib === '') {
    fwrite(STDERR, "bw_lib.php wurde nicht gefunden.\n");
    exit(1);
}
require_once $bw_lib;

/* Ein unbekannter Schalter darf nicht ARBEITEN. Ein Tippfehler soll eine
   Antwort ergeben und keinen Lauf - bei einem Werkzeug, das etwas an eine
   fremde Anlage schickt, ist das nicht Kosmetik. */
$bw_argv = isset($argv) ? $argv : array();
$bw_bekannt = array('--jetzt', '--probe', '--mqtt-leeren', '--sonne-hoeren');
foreach ($bw_argv as $bw_i => $bw_a) {
    if ($bw_i === 0 || strncmp((string) $bw_a, '--', 2) !== 0) {
        continue;
    }
    if (!in_array($bw_a, $bw_bekannt, true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $bw_a . ' - bekannt sind '
                     . implode(', ', $bw_bekannt) . "\n");
        exit(2);
    }
}
$bw_jetzt = in_array('--jetzt', $bw_argv, true);
$bw_probe = in_array('--probe', $bw_argv, true);
$bw_leeren = in_array('--mqtt-leeren', $bw_argv, true);
$bw_hoeren = in_array('--sonne-hoeren', $bw_argv, true);

/* Ohne Wurzel oder aus einem Archiv heraus: nichts senden, nichts schreiben.
   Bis 0.9.19 lief der Lauf auch dann - aus einem Archiv unter der Wurzel
   einer Anlage (oder mit deren LBHOMEDIR) mit deren Konfiguration, deren
   Miniserver und deren stand.json (in WSL gemessen,
   Pruefung-Beschattungswaechter-0.9.20, Faelle A1-A3, A8). */
bw_keine_wurzel_abbruch('bw_lauf.php');

if ($bw_leeren) {
    /* Aufgerufen aus uninstall/uninstall. */
    exit(bw_mqtt_leeren());
}

if ($bw_hoeren) {
    /* Sonne-1: der Begleitprozess des Takts (bw_sonne_hoerer_starten(),
       Abschnitt J in bw_lib.php). Die Kennung ist das erste Argument ohne
       "--"; ohne gueltige Kennung endet er sofort. */
    $bw_hid = '';
    foreach ($bw_argv as $bw_i => $bw_a) {
        if ($bw_i > 0 && strncmp((string) $bw_a, '--', 2) !== 0) {
            $bw_hid = (string) $bw_a;
            break;
        }
    }
    exit(bw_sonne_hoeren($bw_hid));
}

$c = bw_config();
/* Die Abodatei des Gateways folgt dem Praefix (M4, Durchgang 30.09.2026). Der
   Installer legt bei jedem Einbau die mitgelieferte mit dem Vorgabepraefix
   ab; ein anderes Praefix steht damit erst nach diesem Lauf wieder darin.
   Geschrieben wird nur, wenn sie abweicht. */
bw_abo_datei(isset($c['mqtt_thema']) ? $c['mqtt_thema'] : '', true);
/* DER WAECHTER DER BEFEHLSBRUECKE (Gruppe 23, V1, E-E3): im Fuenfminutentakt, VOR dem Start des Haus-Hoerers und vor
   jeder Sperre - befehlsbruecke.sh schliesst geerbte Deskriptoren, der Dienst erbt lauf.lock nie. Er startet den
   Dienst nur mit "Befehle ueber MQTT annehmen" und MQTT an, nie waehrend eines Updates, nach einem Start hoechstens
   alle 600 s neu (Stammfassung 1.0.1). Nicht bei --probe (--mqtt-leeren und --sonne-hoeren enden oben). */
if (!$bw_probe && bw_bruecke_laden()) {
    try {
        bb_waechter();
    } catch (Throwable $bw_t) {
        bw_log_wenn_neu('bruecke', 'Waechter der Befehlsbruecke: ' . get_class($bw_t) . ': ' . $bw_t->getMessage());
    }
}
$stand = bw_stand_lesen();
$letzte = isset($stand['letzte']) ? (int) $stand['letzte'] : 0;
$alter = $letzte > 0 ? (time() - $letzte) : PHP_INT_MAX;
$ziele = bw_ziele($c);

/* NACH EINEM FEHLSCHLAG WIRD FRUEHER WIEDER ANGEKLOPFT.
 *
 * Bis 0.9.12 setzte der Lauf 'letzte' auch dann, wenn KEIN einziges Ziel
 * erreichbar war - der naechste Versuch kam damit erst nach dem vollen
 * Abstand. War der Miniserver in der einen Minute im Neustart, geschah mit
 * der Werkseinstellung bis zu einer Stunde nichts mehr, obwohl der Cron alle
 * fuenf Minuten anklopfte. Solange Fehler in Folge stehen, gilt deshalb die
 * kuerzere Frist. */
$bw_fehlerstand = isset($stand['fehler']) ? (int) $stand['fehler'] : 0;
$wartezeit = ($bw_fehlerstand > 0)
    ? min((int) $c['abstand'], 15) * 60
    : (int) $c['abstand'] * 60;

/* EIN HALBER TAKT TOLERANZ, und zwar aus einem gemessenen Grund.
 *
 * 'letzte' wird NACH dem Senden gesetzt, also ein paar Sekunden nach dem
 * Beginn des Laufs. Der Takt bei genau +60 Minuten sah damit 3598 Sekunden,
 * verglich sie mit 3600 und uebersprang - gesendet wurde erst fuenf Minuten
 * spaeter, und das wanderte mit jeder Stunde weiter. Aus 60 Minuten wurden
 * 65, aus dem kleinsten Abstand (5) wurden 10, also das Doppelte. */
$bw_toleranz = 150;

/* SONNE-1 (Verbesserungsbau 01.10.2026), AB WERK AUS: war der letzte Befehl
   ein nachgeholter (ein Ziel, das der Takt ohne Sonne weggelassen hatte),
   zaehlt der Abstand weiter ab dem regulaeren Befehl davor - sonst schoebe
   jedes Nachholen den naechsten Druck aller uebrigen Ziele hinaus. Jeder
   andere Befehl (jetzt, Endpunkt, Knopf) macht diesen Stand ungueltig
   (bw_sonne_takt_stand()). Ohne Haken aendert sich nichts. */
$bw_sonne_vorher = !empty($c['sonne_ein']) ? bw_sonne_takt_stand($stand) : null;
if ($bw_sonne_vorher !== null) {
    $alter = max(0, time() - $bw_sonne_vorher['regel']);
}

$grund = '';
/* Nicht jeder Grund, nichts zu tun, ist eine Stoerung: abgeschaltet und
   ausserhalb des Zeitfensters sind der bestimmungsgemaesse Betrieb. Nur ein
   fehlendes Ziel ist einer - und genau diese Unterscheidung traegt das Feld
   OK nach Loxone. */
$bw_stoerung = false;
if (!$ziele) {
    $grund = 'kein Ziel eingerichtet';
    $bw_stoerung = true;
} elseif (empty($c['aktiv']) && !$bw_jetzt) {
    $grund = 'abgeschaltet';
} elseif (!bw_im_fenster($c) && !$bw_jetzt) {
    $grund = 'ausserhalb des Zeitfensters ' . $c['von'] . '-' . $c['bis'];
} elseif ($alter + $bw_toleranz < $wartezeit && !$bw_jetzt) {
    $grund = sprintf('erst %d von %d Minuten seit dem letzten Befehl',
                     (int) floor($alter / 60), (int) round($wartezeit / 60));
}

/* SONNENSTAND DER FENSTERBILANZ (Sonne-1, Verbesserungsbau 01.10.2026), AB
   WERK AUS - Abschnitt J in bw_lib.php. Nie bei --jetzt: wer den Befehl
   schickt, meint ihn. Der Takt startet zuerst das Mithoeren neu (nicht die
   Probe; vor jeder Sperre). Dann:
   - Nachholen: fehlte sonst NUR der Abstand (eingeschaltet, im Fenster, ein
     Ziel eingerichtet) und hat der Takt zuletzt Ziele ohne Sonne
     weggelassen, gelten jetzt nur diese Ziele als faellig.
   - Je faelliges Ziel: fallen auf JEDE zugeordnete Fassade laut frischem
     Satz keine Sonne, wird es weggelassen (Protokollzeile). Ohne frische
     Aussage wird gedrueckt wie bisher (Protokollzeile). Bleibt keines
     uebrig, ist das wie beim Wetter keine Stoerung (OK bleibt 1).
   Das Wetter fragt danach nur, wenn noch etwas zu druecken ist. */
/* DER HAUS-HOERER (Gruppe 23, E-E5) laeuft mit Sonnenstand ODER Anwesenheit - und startet VOR den Wachen, damit die
   Abwesenheit auch ausserhalb des Zeitfensters (etwa vor 06:00) zaehlt. Vor jeder Sperre. */
if ((!empty($c['sonne_ein']) || !empty($c['anwesend_ein'])) && !$bw_jetzt && !$bw_probe) {
    bw_sonne_hoerer_starten(__FILE__);
}

/* ANWESENHEIT (Gruppe 23, V3, E-E4), AB WERK AUS - Abschnitt L in bw_lib.php. Meldet der BLE-Scanner NG live
   "niemand da" und ist die Frist um, drueckt dieser Takt EINMAL je Abwesenheit das A, auch wenn der Abstand noch
   nicht um ist - nur eingeschaltet, im Zeitfenster und mit Ziel (die Wachen oben gelten; uebersprungen wird nur der
   Abstand). Sonne und Wetter gelten danach weiter; halten sie zurueck, bleibt der Druck offen. Schweigt die Quelle
   (Zaehler > 120 s) oder kommt "-", gilt das bisherige Verhalten. Nie bei --jetzt. */
$bw_anw = null;
$bw_anw_druck = false;
if (!empty($c['anwesend_ein']) && !$bw_jetzt) {
    $bw_anw = bw_anwesend_lage(bw_anwesend_lesen(), time(), (int) $c['anwesend_frist'] * 60);
    if (bw_anwesend_faellig($bw_anw, $stand) && $ziele && !empty($c['aktiv']) && bw_im_fenster($c)) {
        $grund = '';
        $bw_anw_druck = true;
    }
    if (!$bw_probe && ($bw_anw[0] === 'schweigt' || $bw_anw[0] === 'ohne_aussage')) {
        bw_log_wenn_neu('anwesend_schweigt', 'Anwesenheit: haus/anwesenheit '
            . ($bw_anw[0] === 'schweigt' ? 'schweigt (Zaehler aelter als 120 s oder nie live gesehen)' : 'meldet keine Aussage (-)')
            . ' - bisheriges Verhalten, das A kommt im Abstand.');
    }
}

$bw_sonne = null;
$bw_nachholen = false;
if (!empty($c['sonne_ein']) && !$bw_jetzt) {
    if ($grund !== '' && $ziele && !empty($c['aktiv']) && bw_im_fenster($c)) {
        $bw_offen = bw_sonne_offene_ziele($stand, $ziele);
        if ($bw_offen) {
            $bw_nachholen = true;
            $ziele = $bw_offen;
            $grund = '';
        }
    }
    if ($grund === '') {
        $bw_sonne = bw_sonne_entscheiden($c, $ziele, !$bw_probe);
        if (!$bw_sonne['frei']) {
            $grund = 'Sonne: ' . bw_sonne_protokollsatz($bw_sonne);
        } else {
            $ziele = $bw_sonne['frei'];
        }
    }
}

/* WETTER AUS DER ECOWITT-WEICHE (Wetter-1, Verbesserungsbau 30.09.2026), AB
   WERK AUS. Gefragt wird erst, wenn ein Befehl sonst faellig waere - und nie
   bei --jetzt: wer den Befehl schickt, meint ihn. Ein Zurueckhalten ist keine
   Stoerung (OK bleibt 1); der naechste Takt fragt erneut. Schweigt die Weiche
   laenger als bw_wetter_frist(), gilt das bisherige Verhalten. Die Probe
   fragt, legt aber nichts ab. */
$bw_wetter = null;
if ($grund === '' && !$bw_jetzt && !empty($c['wetter_ein'])) {
    $bw_wetter = bw_wetter_entscheiden($c, !$bw_probe);
    if ($bw_wetter['sperrt'] !== '') {
        $grund = 'Wetter: ' . bw_wetter_protokollsatz($bw_wetter);
    }
}

if ($bw_probe) {
    /* Der Trockenlauf laeuft durch DENSELBEN Weg - alle Wachen greifen echt,
       nur das Senden unterbleibt. Und er uebernimmt NICHT den Wortlaut des
       Ernstfalls: eine Probe, die "gesendet" meldet, waehrend nichts
       gesendet wurde, ist eine stille Falschaussage. */
    bw_trocken(true);
    echo 'PROBE - es wird nichts gesendet.' . "\n";
    if ($bw_wetter !== null) {
        echo '  Wetter (Ecowitt-Weiche): ' . bw_wetter_protokollsatz($bw_wetter) . "\n";
    }
    if ($bw_sonne !== null) {
        echo '  Sonne (Fensterbilanz' . ($bw_nachholen ? ', Nachholen' : '') . '): '
           . bw_sonne_protokollsatz($bw_sonne) . "\n";
    }
    if ($bw_anw !== null) {
        echo '  Anwesenheit: ' . ($bw_anw_druck
            ? 'niemand seit ' . (int) floor((time() - $bw_anw[1]) / 60) . ' min - ein regulaerer Lauf drueckte jetzt einmal (Abwesenheit)'
            : $bw_anw[0]) . "\n";
    }
    if ($grund !== '') {
        echo '  Ein regulaerer Lauf taete jetzt nichts: ' . $grund . "\n";
    }
    foreach ($ziele as $z) {
        $r = bw_senden($c, $z['uuid'], $z['befehl']);
        echo sprintf('  Ziel %d: %s an %s  ->  %s', $z['nr'], $z['befehl'], $z['uuid'],
                     $r['ok'] ? $r['url'] : $r['text']) . "\n";
    }
    if (!$ziele) { echo '  Es ist kein Ziel eingerichtet.' . "\n"; }
    bw_trocken(false);
    exit(0);
}

if ($grund !== '') {
    /* Auch ein Lauf, der nichts sendet, gibt ein Lebenszeichen ab: ZAEHLER
       und der Zeitstempel gehen weiter.
     *
     * OK sagt dabei NICHT "es wurde gesendet", sondern "der Durchgang endete
     * ohne Stoerung". Bis 0.9.12 stand hier fest false, und damit stand OK
     * mit der Werkseinstellung 55 von 60 Minuten je Stunde auf 0 und nachts
     * durchgehend - ohne dass irgendetwas war. Wer darauf in Loxone eine
     * Ueberwachung legte, hatte eine Dauerstoerung. */
    /* Eine misslungene Zaehlung ist eine Stoerung, bis wieder eine gelingt
       (C3, Frage 6/11) - OK und status/ok gehen darueber auf 0. */
    /* DIE WIRKUNG DES LETZTEN BEFEHLS NACHMESSEN (b1, Verbesserungsbau
       30.09.2026): nur mit eingeschalteter Wirkungsmessung und nur, solange
       eine Messung offen ist. Dieselbe Sperre wie jeder Weg, der stand.json
       lesen-aendern-schreibend anfasst; ist sie belegt, misst der naechste
       Takt. */
    /* B23 (Gruppe 23, Entscheidung 2, E-E8): im Zeitfenster bei eingeschaltetem Waechter und Haken "Die Wirkung
       messen" alle 15 Minuten zaehlen, auch ohne Befehl - unter derselben Sperre, mit dem Stand UNTER der Sperre
       frisch gelesen (B21), nur autoActive, Abbruch nach drei Fehlschlaegen in Folge oder 60 s. */
    $bw_zfaellig = bw_zaehlen_faellig($c, $stand, time(), bw_im_fenster($c));
    if (bw_wirkung_offen($c, $stand) || $bw_zfaellig) {
        $bw_nsperre = bw_sperre();
        if ($bw_nsperre !== false) {
            $bw_st = bw_stand_lesen();
            if (bw_wirkung_offen($c, $bw_st)) {
                $bw_st = bw_wirkung_nachmessen($c, $bw_st);
            }
            if (bw_zaehlen_faellig($c, $bw_st, time(), bw_im_fenster($c))) {
                list($bw_zok, $bw_zmeldung, $bw_zerg) = bw_automatiken($c, 40, true, 60);
                $bw_st = bw_zaehlung_eintragen($bw_st, $bw_zok, $bw_zerg);
                if (!$bw_zok) {
                    bw_log_wenn_neu('pruefen', 'Die Automatiken liessen sich nicht zaehlen: '
                                             . strip_tags((string) $bw_zmeldung));
                }
            }
            bw_stand_schreiben($bw_st);
            fclose($bw_nsperre);
            $stand = bw_stand_lesen();
        }
    }
    bw_lauf_schreiben(!$bw_stoerung && !bw_zaehlung_misslungen($c, $stand));
    bw_nach_update_merker_weg();
    /* VOLLVERSAND IN JEDEM TAKT (Gruppe 23, N1, E-E9): ok, fehler, code, gesendet, scharf, automatiken und alter
       standen ueber MQTT bis 0.9.30 bis zum naechsten Befehl still; unter Gateway V2 stuende ziele nach einem
       Neustart des Miniservers bis zu 30 min auf 0. bw_mqtt_publish() traegt Zustaende und Lebenszeichen in EINER
       Verbindung und fuehrt den Merker der drei Zustaende mit (M1). */
    bw_mqtt_publish($c, $stand);
    bw_melden($c);
    echo "nichts zu tun: " . $grund . "\n";
    exit(0);
}

/* Erst ab hier wird gesendet - also erst ab hier die Sperre. Sie ist nicht
   blockierend: wer nicht drankommt, geht wieder, der naechste Takt kommt in
   fuenf Minuten ohnehin. Die Datei muss offen BLEIBEN, sonst faellt die
   Sperre sofort. */
$bw_sperre = bw_sperre();
if ($bw_sperre === false) {
    if (bw_sperre_lage() === 'rechte') {
        /* N10 (Gruppe 23, B15): lauf.lock laesst sich nicht oeffnen - kein "anderer Lauf", sondern eine Stoerung.
           OK und status/ok gehen auf 0, Befund und Healthcheck sagen "Sperrdatei nicht beschreibbar". */
        bw_lauf_schreiben(false);
        bw_mqtt_publish($c, $stand);
        bw_melden($c);
        echo "nichts zu tun: lauf.lock laesst sich nicht oeffnen (Rechte?) - siehe Protokoll\n";
        exit(0);
    }
    echo "nichts zu tun: ein anderer Lauf ist noch unterwegs\n";
    exit(0);
}
/* B21 (Gruppe 23): den Stand UNTER der Sperre frisch lesen. Hat in der Zwischenzeit (Sonne- und Wetterentscheidung,
   Wetterabruf) der Endpunkt, die Bruecke oder der Knopf gesendet, endet der Takt hier - bis 0.9.30 ging deren Stand
   verloren, und das A kam doppelt. --jetzt sendet trotzdem. */
$bw_frisch = bw_stand_lesen();
if (!$bw_jetzt && (isset($bw_frisch['letzte']) ? (int) $bw_frisch['letzte'] : 0) !== $letzte) {
    fclose($bw_sperre);
    bw_lauf_schreiben(!bw_zaehlung_misslungen($c, $bw_frisch));
    bw_nach_update_merker_weg();
    bw_mqtt_publish($c, $bw_frisch);
    bw_melden($c);
    echo "nichts zu tun: soeben gesendet (Endpunkt, Befehlsbruecke oder Knopf)\n";
    exit(0);
}
$stand = $bw_frisch;

$gut = 0;
/* ZWEI CODES, NICHT EINER. Bis 0.9.12 stand hier ein unbedingtes
   $code = $r['code'] in der Schleife - bei zwei Zielen, von denen das erste
   tot und das zweite gut war, meldete der Stand HTTP 200 UND einen erhoehten
   Fehlerzaehler. Der Healthcheck sagte dann "1 Fehler, HTTP 200", und das ist
   keine Auskunft, sondern ein Raetsel. Gilt der Code des ERSTEN Fehlschlags;
   erst wenn keiner fehlschlug, der des letzten guten Ziels. */
$code_fehler = 0;
$code_gut = 0;
$letzter_text = '';
$ergebnisse = array();
/* Die Positionen VOR dem Befehl (b1) - null, wenn die Wirkungsmessung aus ist. */
$bw_vorher = bw_wirkung_vorher($c);
foreach ($ziele as $z) {
    $r = bw_senden($c, $z['uuid'], $z['befehl']);
    $ergebnisse[] = $r;
    if ($r['ok']) {
        $gut++;
        $code_gut = (int) $r['code'];
        continue;
    }
    $letzter_text = $r['text'];
    if ($code_fehler === 0) { $code_fehler = (int) $r['code']; }
    /* Die Bremse steht hier, seit der Fehlschlag alle 15 Minuten statt
       stuendlich wiederholt wird: sonst schreibt ein toter Miniserver das
       Protokoll voll und draengt genau die Zeilen weg, die erklaeren, wann
       es angefangen hat. Der HTTP-Code steht IM Merker - eine andere Art zu
       scheitern wird dadurch sofort sichtbar und nicht erst in einer
       Stunde. */
    bw_log_wenn_neu('senden' . $z['nr'] . '_' . (int) $r['code'],
        sprintf('FEHLER beim Senden an Ziel %d: HTTP %d %s  (%s)',
                $z['nr'], $r['code'], $r['text'], $r['url']));
}
$code = ($gut === count($ziele)) ? $code_gut : $code_fehler;
$alles = ($gut === count($ziele));

$heute = date('Y-m-d');
$letzter_tag = isset($stand['tag']) ? (string) $stand['tag'] : '';

/* Dieselbe Rechnung wie am Endpunkt und am Knopf der Oberflaeche (C2) - sie
   ist die, die hier schon stand, herausgezogen nach bw_stand_nach_senden(). */
$stand = bw_stand_nach_senden($stand, $ergebnisse);
$stand['tag'] = $heute;
/* b1: die Nachmessung beginnt; der naechste Takt vergleicht. */
$stand = bw_wirkung_beginnen($stand, $bw_vorher, $ergebnisse);
/* Sonne-1: was der Takt weggelassen hat, und ab wann der Abstand zaehlt. */
if ($bw_sonne !== null) {
    $stand = bw_sonne_stand_eintragen($stand, $bw_sonne, $bw_sonne_vorher, $bw_nachholen);
}
/* V3: der eine Druck dieser Abwesenheit ist mit diesem Sendelauf verbraucht (auch bei einem Teilerfolg; Fehlschlaege
   wiederholt die 15-Minuten-Regel). Ein regulaerer Befehl waehrend der Abwesenheit verbraucht ihn ebenso. */
if ($bw_anw !== null && bw_anwesend_faellig($bw_anw, $stand)) {
    $stand['anwesend_a'] = (int) $bw_anw[1];
    $stand['anwesend_a_ts'] = time();
}

/* Die Wirkung messen, nicht den Rueckgabewert - aber nur, wenn der Anwender
   es eingeschaltet hat, und hoechstens einmal je Viertelstunde: die Messung
   fragt je Jalousie mehrere Zustaende ab. */
if (!empty($c['pruefen_ein'])) {
    $letzte_pruefung = isset($stand['scharf_ts']) ? (int) $stand['scharf_ts'] : 0;
    if (time() - $letzte_pruefung >= 900) {
        /* Gruppe 23 (E-E8): im Takt nur autoActive, Abbruch nach drei Fehlschlaegen in Folge oder 60 s. */
        list($pok, $pmeldung, $perg) = bw_automatiken($c, 40, true, 60);
        /* Eine Stelle fuer alle drei Wege (C3): gelungen traegt die Werte
           ein, misslungen vermerkt den Fehlschlag - die Werte bleiben
           stehen, OK geht auf 0 (Frage 6/11). */
        $stand = bw_zaehlung_eintragen($stand, $pok, $perg);
        if (!$pok) {
            bw_log_wenn_neu('pruefen', 'Die Automatiken liessen sich nicht zaehlen: '
                                     . strip_tags((string) $pmeldung));
        }
    }
}

/* EINMAL schreiben, nicht zweimal. Bis 0.9.10 stand hier ein zweiter
   Schreibvorgang, nur damit der Tag nachkam - auf einer Speicherkarte ist
   das die doppelte Schreiblast fuer nichts. */
bw_stand_schreiben($stand);
/* status/ok und OK gehen auch dann auf 0, wenn die eingeschaltete Zaehlung
   misslungen ist (C3, Frage 6/11). */
bw_lauf_schreiben($alles && !bw_zaehlung_misslungen($c, $stand));
bw_nach_update_merker_weg();
bw_mqtt_publish($c, $stand);
bw_melden($c);

/* Aufgeschrieben wird der ERSTE Befehl des Tages und jeder Fehler - nicht
   jeder Lauf. Ein Fuenfminutentakt erzeugt sonst dreihundert Zeilen am Tag,
   in denen die eine wichtige untergeht. */
if ($alles && $letzter_tag !== $heute) {
    bw_log(sprintf('erster Befehl des Tages abgesetzt: %d Ziel(e), HTTP %d',
                   count($ziele), $code));
}

/* Sonne-1: ein nachgeholtes A steht immer im Protokoll - es kommt hoechstens
   einmal je Ziel und Abstand vor. */
if ($bw_nachholen) {
    $bw_nrs = array();
    foreach ($ziele as $z) { $bw_nrs[] = (int) $z['nr']; }
    bw_log(sprintf('Sonne-1: A fuer Ziel %s nachgeholt (jetzt Sonne oder keine frische Aussage): '
                   . '%d von %d angenommen, HTTP %d', implode(', ', $bw_nrs), $gut, count($ziele), $code));
}
if ($bw_anw_druck) {
    $bw_nrs = array();
    foreach ($ziele as $z) { $bw_nrs[] = (int) $z['nr']; }
    bw_log(sprintf('Anwesenheit: niemand seit %d min - A an Ziel %s (%d von %d angenommen, HTTP %d)',
                   (int) floor((time() - $bw_anw[1]) / 60), implode(',', $bw_nrs), $gut, count($ziele), $code));
}
if ($bw_sonne !== null && ($bw_sonne['weg'] || $bw_sonne['ohne'])) {
    echo 'Sonne-1: ' . bw_sonne_protokollsatz($bw_sonne) . "\n";
}
echo ($alles ? 'gesendet' : 'FEHLER') . ': ' . $gut . ' von ' . count($ziele)
   . ' Ziel(en), HTTP ' . $code . ' ' . substr($letzter_text, 0, 100) . "\n";
exit($alles ? 0 : 1);
