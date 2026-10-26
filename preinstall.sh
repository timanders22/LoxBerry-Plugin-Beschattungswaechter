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
# B18 (Gruppe 23, 0.9.31): ein Update erkennt nur eine GUELTIGE Marke - Inhalt Unix-Zeit aus preupgrade.sh, sonst die
# Aenderungszeit; gilt bei -300 <= jetzt - t < 3600 (wie bw_marke_gilt() in bw_lib.php); laesst sich keine Zeit lesen,
# gilt sie. Eine aeltere Marke stammt aus einem abgebrochenen Update - dann ist dies eine Neuinstallation. Bis 0.9.30
# hielt jede liegengebliebene Marke den naechsten Einbau fuer ein Update (Pruefbericht B18).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    MT=$(head -c 16 "$MARKE" 2>/dev/null | tr -d '[:space:]')
    case "$MT" in ''|*[!0-9]*) MT=$(stat -c %Y "$MARKE" 2>/dev/null || echo 0) ;; esac
    JETZT=$(date +%s)
    if [ "${MT:-0}" -le 0 ] 2>/dev/null; then
        exit 0
    fi
    MA=$((JETZT - MT))
    if [ "$MA" -ge -300 ] && [ "$MA" -lt 3600 ]; then
        exit 0
    fi
    echo "<WARNING> Eine Aktualisierungsmarke von $MA s liegt noch da (abgebrochenes Update?) - dieser Einbau gilt als Neuinstallation."
    rm -f "$MARKE" 2>/dev/null
fi

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.backup.json.neu" \
            "$BASE/config/plugins/$PFOLDER.backup.json.kaputt" \
            "$BASE/config/plugins/$PFOLDER.mqtt_praefix_alt"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        # B3 (Gruppe 23): rm -rf - ein Ordner .alt liesse mv die Datei sonst HINEINschieben; chmod nur auf eine
        # Datei, nie auf einen Symlink (Bauform Audi 0.9.30, Abfahrt 1.6.32, Marstek 1.1.27, Govee 0.9.31).
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
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
