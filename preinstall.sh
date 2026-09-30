#!/bin/bash
# Beschattungswaechter - preinstall
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Neu in 0.9.22 (I1, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22. Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem
# Aufraeumen der alten Fassung und VOR dem Kopieren von Konfiguration,
# Cron-Datei und Oberflaeche (sbin/plugininstall.pl: preupgrade :846, purge
# :874, preinstall :877, Cron :990 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt -
# kein Altersvergleich. Dann tut es nichts: die Zweitschrift braucht
# postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Was eine fruehere Installation
# neben den Ordnern liegen liess - die Zweitschrift der Konfiguration samt
# Zwischenstufe .neu und unlesbarer Fassung .kaputt, das gemerkte alte
# MQTT-Praefix -, geht nach <name>.alt, gemeldet mit genau einer <WARNING>.
# Bis 0.9.21 spielte postinstall.sh eine liegengebliebene Zweitschrift
# ungefragt zurueck, und ohne postinstall.sh holte sie die Bibliothek beim
# ersten Seitenaufruf: Kennung, Merkwort und Einschaltzustand einer frueheren
# Anlage, und der erste Takt schickte den Befehl mit der alten Kennung (in WSL
# gemessen, Pruefbericht Installer I1, Faelle N2 und N3). Die Bibliothek liest
# .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-beschattungswaechter}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzel wie in den uebrigen Hakenskripten: ohne config/plugins, data/plugins
# UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.backup.json.neu" \
            "$BASE/config/plugins/$PFOLDER.backup.json.kaputt" \
            "$BASE/config/plugins/$PFOLDER.mqtt_praefix_alt"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -f "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            chmod 600 "$ZIEL.alt" 2>/dev/null
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation (Kennung, Merkwort, Einschaltzustand) werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
