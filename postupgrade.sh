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
echo "<OK> postupgrade abgeschlossen."
echo "<INFO> Der Zaehler faengt nach einem Update wieder bei null an -"
echo "<INFO> das Protokoll im Reiter Protokoll bleibt erhalten."
exit 0
