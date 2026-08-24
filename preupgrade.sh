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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
CF="$BASE/config/plugins/$PFOLDER/beschattung.json"
if [ -f "$CF" ]; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json" \
        && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null \
        && echo "<OK> Konfiguration gesichert."
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
