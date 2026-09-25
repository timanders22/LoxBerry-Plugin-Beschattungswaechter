#!/bin/bash
# Beschattungswaechter - preupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Reihenfolge des Installers:
#   preupgrade -> config/* aus dem Archiv -> postinstall -> postupgrade
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Kopierschritt tun, also hier - und nicht nach /tmp, das fluechtig ist.
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
    echo "<WARNING> config/system/general.json. Es wurde nichts gesichert."
    exit 1
fi
CF="$BASE/config/plugins/$PFOLDER/beschattung.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
# Gesichert wird nach INHALT, und die Sicherung entsteht neben ihrem Platz,
# wird geprueft und erst dann umbenannt (Regeln/06). Die Sicherung ist
# zugleich die Zweitschrift, aus der bw_lib.php heilt. Bis 0.9.19 wurde sie
# mit jedem Stand ueberschrieben - auch mit {} oder einer Datei ohne Kennung
# und Merkwort, und die einzige Kopie mit Inhalt war fort (Fall Z5).
# INHALT heisst: eine Kennung (uuid) oder ein Merkwort (aktionstoken) steht
# darin - dieselbe Regel wie bw_hat_inhalt() in bw_lib.php.
bw_hat_inhalt() {
    [ -f "$1" ] || return 1
    grep -Eq '"uuid"[[:space:]]*:[[:space:]]*"[A-Za-z0-9_./-]+"' "$1" 2>/dev/null && return 0
    grep -Eq '"aktionstoken"[[:space:]]*:[[:space:]]*"[0-9a-f]{16,64}"' "$1" 2>/dev/null
}
if [ ! -f "$CF" ]; then
    echo "<INFO> Es gibt keine Konfiguration - nichts zu sichern."
elif ! bw_hat_inhalt "$CF"; then
    if bw_hat_inhalt "$BK"; then
        echo "<WARNING> Die Konfiguration traegt weder Kennung noch Merkwort. Die Sicherung"
        echo "<WARNING> $BK traegt Inhalt und bleibt unveraendert."
    else
        echo "<INFO> Die Konfiguration traegt weder Kennung noch Merkwort - nichts zu sichern."
    fi
elif cp -p "$CF" "$BK.neu" 2>/dev/null && chmod 600 "$BK.neu" 2>/dev/null \
     && cmp -s "$CF" "$BK.neu" && mv -f "$BK.neu" "$BK"; then
    echo "<OK> Konfiguration gesichert."
else
    rm -f "$BK.neu"
    echo "<WARNING> Die Konfiguration liess sich nicht sichern; eine vorhandene Sicherung bleibt unveraendert."
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
