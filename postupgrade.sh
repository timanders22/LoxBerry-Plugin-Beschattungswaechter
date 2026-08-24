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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
rm -f "$BASE/data/plugins/$PFOLDER/stand.json.tmp"
rm -f "$BASE/config/plugins/$PFOLDER/beschattung.json.tmp"
echo "<OK> postupgrade abgeschlossen."
echo "<INFO> Der Zaehler faengt nach einem Update wieder bei null an -"
echo "<INFO> das Protokoll im Reiter Protokoll bleibt erhalten."
exit 0
