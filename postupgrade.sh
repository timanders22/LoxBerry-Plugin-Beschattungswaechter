#!/bin/bash
# Beschattungswaechter - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin. Hier passiert mit Absicht fast
# nichts.
#
# BERICHTIGUNG 24.08.2026: bis 0.9.3 stand hier, der Zaehler in stand.json
# ueberlebe das Update. Das ist falsch - der Installer raeumt beim Upgrade
# data/plugins/<ordner>/ mit weg, bevor postupgrade ueberhaupt laeuft
# (nachgelesen im Installationslog: "removed .../data/plugins/.../stand.json").
# Der Zaehler faengt nach jedem Update wieder bei null an.
#
# Das PROTOKOLL unter log/plugins/<ordner>/ bleibt dagegen liegen - und es ist
# ohnehin die belastbarere Aufzeichnung, weil dort je Tag eine Zeile mit
# Zeitstempel steht statt einer blossen Zahl.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-beschattungswaechter}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Die LoxBerry-Wurzel. Der Installer uebergibt sie als fuenftes Argument;
# $LBHOMEDIR gilt nur mit config/plugins UND data/plugins darunter. Sonst wird
# vom eigenen Ablageort aufwaerts gesucht, und Wurzel ist nur, was
# config/plugins, data/plugins UND config/system/general.json traegt
# (Regeln/06). Ohne Wurzel wird gewarnt und nichts getan.
#
# Bis 0.9.19 stand hier ein fester Rueckfall ohne jede Pruefung ($SELF/../..,
# in uninstall/uninstall $SELF/../../.. und der Ordnername aus $SELF/..). Aus
# einem fremden Baum heraus sicherte preupgrade.sh dessen Konfiguration ueber
# dessen Sicherung, postinstall.sh legte dort Ordner an, postupgrade.sh
# loeschte dort Dateien, und uninstall/uninstall rechnete sich den Ordnernamen
# "system" aus (in WSL gemessen, Pruefung-Beschattungswaechter-0.9.20, Faelle
# W1-W6).
bw_wurzel_suchen() {
    bw_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    bw_i=0
    while [ -n "$bw_v" ] && [ "$bw_v" != "/" ] && [ $bw_i -lt 8 ]; do
        if [ -d "$bw_v/config/plugins" ] && [ -d "$bw_v/data/plugins" ] \
           && [ -f "$bw_v/config/system/general.json" ]; then
            echo "$bw_v"; return 0
        fi
        bw_v=$(dirname "$bw_v"); bw_i=$((bw_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(bw_wurzel_suchen) || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb dieses Skripts"
    echo "<WARNING> traegt kein Verzeichnis config/plugins, data/plugins und"
    echo "<WARNING> config/system/general.json. Es wurde nichts entfernt."
    exit 1
fi
rm -f "$BASE/data/plugins/$PFOLDER/stand.json.tmp"
rm -f "$BASE/config/plugins/$PFOLDER/beschattung.json.tmp"

# ---------- endpunkt_ein fuer bestehende Anlagen (Gruppe 23, 0.9.31, E-E13) ----------
# Neu in 0.9.31: der HTTP-Lesezweig des Endpunkts (status, json) haengt am Haken endpunkt_ein - ab Werk aus, fuer eine
# bestehende Anlage an, damit ihre Loxone-Eingaenge weiterlaufen. Fuer Konfiguration UND Zweitschrift: traegt die Datei
# Inhalt (bw_hat_inhalt(): Kennung oder Merkwort) und fehlt endpunkt_ein, wird er mit 1 geschrieben - Nebendatei .neu
# mit umask 077, Gegenprobe (alles wie vorher, dazu endpunkt_ein 1), mv -f, chmod 600. Scheitert es, gilt die
# Lese-Regel in bw_config() (bw_uebergang()) - der erste Takt schreibt den Schluessel dann selbst.
BW_LIB=""
for bw_k in "${6:+$6/webfrontend/html/bw_lib.php}" "$BASE/webfrontend/html/plugins/$PFOLDER/bw_lib.php"; do
    if [ -n "$bw_k" ] && [ -f "$bw_k" ]; then
        BW_LIB="$bw_k"
        break
    fi
done
bw_endpunkt_ein() {
    bw_f="$1"
    [ -f "$bw_f" ] && [ ! -L "$bw_f" ] || return 0
    if [ -z "$BW_LIB" ] || ! command -v php >/dev/null 2>&1; then
        echo "<INFO> $bw_f liess sich nicht pruefen (php oder bw_lib.php fehlt) - es gilt die Lese-Regel (endpunkt_ein=1 fuer bestehende Anlagen)."
        return 0
    fi
    rm -f "$bw_f.neu" 2>/dev/null
    php -r 'require $argv[1]; $d = json_decode((string) @file_get_contents($argv[2]), true); if (!is_array($d) || !bw_hat_inhalt($d) || array_key_exists("endpunkt_ein", $d)) { exit(3); } $d["endpunkt_ein"] = 1; $js = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); if (!is_string($js) || $js === "") { exit(2); } umask(077); exit(@file_put_contents($argv[2] . ".neu", $js) === strlen($js) ? 0 : 2);' "$BW_LIB" "$bw_f" >/dev/null 2>&1
    bw_rc=$?
    if [ "$bw_rc" -eq 3 ]; then
        rm -f "$bw_f.neu" 2>/dev/null
        return 0
    fi
    if [ "$bw_rc" -eq 0 ] && php -r '$o = json_decode((string) @file_get_contents($argv[1]), true); $n = json_decode((string) @file_get_contents($argv[1] . ".neu"), true); $o["endpunkt_ein"] = 1; exit(is_array($n) && $n == $o ? 0 : 1);' "$bw_f" >/dev/null 2>&1 \
       && mv -f "$bw_f.neu" "$bw_f" 2>/dev/null; then
        chmod 600 "$bw_f" 2>/dev/null
        echo "<INFO> Konfiguration ergaenzt: endpunkt_ein=1 in $bw_f (bestehende Anlage - der HTTP-Lesezweig bleibt erreichbar; abschalten im Reiter Einbindung)."
    else
        rm -f "$bw_f.neu" 2>/dev/null
        echo "<WARNING> endpunkt_ein liess sich in $bw_f nicht ergaenzen - es gilt die Lese-Regel; der erste Takt schreibt ihn."
    fi
}
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - endpunkt_ein nicht ergaenzt (es gilt die Lese-Regel)." ;;
    *)
        bw_endpunkt_ein "$BASE/config/plugins/$PFOLDER/beschattung.json"
        bw_endpunkt_ein "$BASE/config/plugins/$PFOLDER.backup.json"
        ;;
esac
echo "<OK> postupgrade abgeschlossen."
echo "<INFO> Der Zaehler faengt nach einem Update wieder bei null an -"
echo "<INFO> das Protokoll im Reiter Protokoll bleibt erhalten."
exit 0
