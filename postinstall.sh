#!/bin/bash
# Beschattungswaechter - postinstall
#
# Der Installer ruft mit:  <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASE> <TEMPFOLDER>
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung. Der absolute Arbeitsordner steht im FUENFTEN Argument.
#
# postinstall laeuft IMMER, auch beim Upgrade. Alles hier muss mehrfach
# ausfuehrbar sein, ohne Schaden anzurichten.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-beschattungswaechter}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PCONFIG="$BASE/config/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"

mkdir -p "$PCONFIG" "$PLOG" "$PDATA" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
chmod 700 "$PCONFIG" 2>/dev/null
[ -f "$PCONFIG/beschattung.json" ] || echo '{}' > "$PCONFIG/beschattung.json"
chmod 600 "$PCONFIG/beschattung.json" 2>/dev/null

BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/beschattung.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || ! grep -q '"' "$CF" 2>/dev/null; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# ---------- Rest der Fassung 0.9.0 wegraeumen ----------
# Die 0.9.0 lieferte cron/cron.15min als VERZEICHNIS statt als Datei aus. Der
# Installer legte daraus system/cron/cron.15min/<plugin>/ an - ein
# Unterverzeichnis, das LoxBerry nie ausfuehrt. Beim Update lag es dem neuen
# Eintrag im Weg. Seit 0.9.2 liegt der Cron in cron.05min; dieser Rest gehoert
# weg, sonst findet ihn nie wieder jemand.
#
# Mit rmdir und nicht mit "rm -rf": entfernt wird genau die eine Datei, die wir
# selbst dort abgelegt haben, und danach das Verzeichnis NUR, wenn es leer ist.
# Liegt dort noch etwas anderes, bleibt alles stehen und wird gemeldet. Auf
# einem fremden Rechner loescht man nicht mehr, als man hingelegt hat.
# In dem Verzeichnis liegen ZWEI Dateien, und das ist der Grund, warum die
# 0.9.3 es nicht leer bekam:
#   beschattungswaechter  - der Eintrag der 0.9.0
#   cron.15min            - die 0.9.1 lieferte eine DATEI aus, der Installer
#                           kopierte sie in das vorhandene Verzeichnis hinein
# Entfernt werden genau diese beiden Namen und sonst keiner.
ALT="$BASE/system/cron/cron.15min/$PFOLDER"
if [ -d "$ALT" ]; then
    rm -f "$ALT/$PFOLDER"
    rm -f "$ALT/cron.15min"
    rm -f "$ALT/cron.05min"
    rmdir "$ALT" 2>/dev/null
    if [ -e "$ALT" ]; then
        echo "<INFO> Der Rest in cron.15min liess sich nicht ganz entfernen - dort"
        echo "<INFO> liegt noch etwas, das nicht von diesem Plugin stammt: $ALT"
    else
        echo "<OK> Rest der Fassung 0.9.0 aus cron.15min entfernt."
    fi
elif [ -f "$ALT" ]; then
    rm -f "$ALT" && echo "<OK> Alter Cron-Eintrag in cron.15min entfernt."
fi

if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> PHP wurde nicht gefunden. Ohne PHP laeuft der Cron nicht."
    exit 1
fi

# Hausregel: jeden Cron-Dienst nach der Installation einmal von Hand aufrufen
# und den Rueckgabewert ansehen. Ein require, das nur im entpackten Archiv
# aufgeht, laeuft installiert NIE - und der Cron schreibt nach /dev/null.
if [ -f "$PBIN/bw_lauf.php" ]; then
    AUS=$(php "$PBIN/bw_lauf.php" --probe 2>&1)
    RC=$?
    if echo "$AUS" | grep -q "bw_lib.php wurde nicht gefunden"; then
        echo "<FAIL> Der Lauf findet seine Bibliothek nicht:"
        echo "$AUS" | sed 's/^/<FAIL> /'
        exit 1
    fi
    if [ $RC -eq 0 ]; then
        echo "<OK> Selbsttest: $(echo "$AUS" | tail -1)"
    else
        echo "<INFO> Der Lauf meldet - vor der Einrichtung ist das normal:"
        echo "$AUS" | head -3 | sed 's/^/<INFO> /'
    fi
else
    echo "<INFO> bw_lauf.php wurde unter $PBIN nicht gefunden - der Selbsttest entfaellt."
fi

if id loxberry >/dev/null 2>&1; then
    chown -R loxberry:loxberry "$PCONFIG" "$PLOG" "$PDATA" "$PBIN" 2>/dev/null
    [ -f "$BK" ] && chown loxberry:loxberry "$BK" 2>/dev/null
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: Miniserver waehlen, Kennung des"
echo "<INFO>     Bausteins pruefen und EINSCHALTEN. Ab Werk ist es aus."
echo "<INFO>  2. Reiter Test: 'Befehl jetzt senden'. Fahren danach Rollladen"
echo "<INFO>     oder wird das A in der App gruen, traegt der Weg."
exit 0
