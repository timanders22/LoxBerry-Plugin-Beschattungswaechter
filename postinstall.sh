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
ARGV6=$6
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
    echo "<WARNING> config/system/general.json. Es wurde nichts angelegt und nichts zurueckgespielt."
    exit 1
fi

PCONFIG="$BASE/config/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"

# I1 (Durchgang 30.09.2026, Entscheidung 1): zurueckgespielt wird aus der
# Zweitschrift NUR bei einer Aktualisierung - und die erkennt dieses Skript
# allein an der Marke, die preupgrade.sh als Erstes anlegt (kein
# Altersvergleich). Festgehalten wird es hier, bevor der trap die Marke
# entfernt. Die Marke faellt ueber einen trap, nicht erst am Dateiende: dieses
# Skript steigt an mehreren Stellen mit "exit 1" aus, und eine liegengebliebene
# Marke hiesse beim naechsten Einbau "Update" und fuer den Healthcheck
# "Aktualisierung laeuft". Eine Kommandoersetzung und eine Unterschale loesen
# den EXIT-Trap nicht aus (Regeln/06, bash 5.2 gemessen).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
UPGRADE=0
[ -f "$MARKE" ] && UPGRADE=1
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

mkdir -p "$PCONFIG" "$PLOG" "$PDATA" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
chmod 700 "$PCONFIG" 2>/dev/null
# I4: nach einem Update steht der erste Takt noch aus - der Healthcheck meldet
# bis dahin einen Hinweis statt "noch nie gelaufen". bw_lauf.php nimmt die
# Marke beim ersten Durchgang weg.
if [ "$UPGRADE" = "1" ]; then
    : > "$PDATA/erster_takt_nach_update" 2>/dev/null
fi
[ -f "$PCONFIG/beschattung.json" ] || echo '{}' > "$PCONFIG/beschattung.json"
chmod 600 "$PCONFIG/beschattung.json" 2>/dev/null

BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/beschattung.json"
# DIE KONFIGURATION WIRD MIT DEM JSON-LESER GEPRUEFT, nicht mit grep (I2,
# Durchgang 30.09.2026). Bis 0.9.21 stand hier eine Textsuche nach "uuid" und
# "aktionstoken": eine abgeschnittene Datei, in der beide noch standen, galt
# als Konfiguration mit Inhalt, ueberschrieb die heile Zweitschrift, und nach
# dem Update war der Waechter ohne Konfiguration und ohne Merkwort - bei
# zweimal "<OK>" im Protokoll (gemessen, Pruefbericht Installer I2).
# Geprueft wird mit bw_hat_inhalt() aus der Bibliothek - dieselbe Regel wie in
# der Oberflaeche, an EINER Stelle: zuerst die Bibliothek DIESES Archivs (der
# Installer uebergibt den Auspackordner als sechstes Argument), sonst die
# installierte.
# Rueckgabe von bw_lage: 0 lesbar mit Kennung oder Merkwort, 1 lesbar ohne,
# 2 fehlt oder unlesbar, 3 nicht pruefbar (keine Bibliothek oder kein php).
BW_LIB=""
for bw_k in "${ARGV6:+$ARGV6/webfrontend/html/bw_lib.php}" "$BASE/webfrontend/html/plugins/$PFOLDER/bw_lib.php"; do
    if [ -n "$bw_k" ] && [ -f "$bw_k" ]; then
        BW_LIB="$bw_k"
        break
    fi
done
bw_lage() {
    [ -f "$1" ] || return 2
    if [ -z "$BW_LIB" ] || ! command -v php >/dev/null 2>&1; then
        return 3
    fi
    php -r 'require $argv[1]; $d = json_decode((string) @file_get_contents($argv[2]), true); if (!is_array($d)) { exit(2); } exit(bw_hat_inhalt($d) ? 0 : 1);' "$BW_LIB" "$1" >/dev/null 2>&1
    bw_rc=$?
    case "$bw_rc" in
        0|1|2) return "$bw_rc" ;;
        *) return 3 ;;
    esac
}

if [ "$UPGRADE" = "1" ]; then
    bw_lage "$CF"
    LAGE_CF=$?
    if [ "$LAGE_CF" -eq 0 ]; then
        echo "<OK> Die Konfiguration ist vorhanden und traegt Kennung oder Merkwort."
    else
        bw_lage "$BK"
        LAGE_BK=$?
        if [ "$LAGE_BK" -eq 0 ]; then
            # Kein cp -p: es braechte die Rechte der Sicherung mit (Regeln/06,
            # "chmod vor cp -p wirkungslos"). Neben dem Platz, dann umbenannt.
            if ( umask 077 && cp "$BK" "$CF.neu" ) 2>/dev/null && mv -f "$CF.neu" "$CF"; then
                # I3: Rechte NACH dem Zurueckspielen, auf Konfiguration UND
                # Zweitschrift - beide tragen das Merkwort. Bis 0.9.21 stand
                # chmod 600 vor dem cp -p, und die zurueckgespielte
                # Konfiguration trug danach 0644 (gemessen, Pruefbericht
                # Installer I3).
                chmod 600 "$CF" 2>/dev/null
                chmod 600 "$BK" 2>/dev/null
                # "wiederhergestellt" erst NACH dem Nachlesen (I2).
                bw_lage "$CF"
                if [ $? -eq 0 ]; then
                    echo "<OK> Konfiguration aus Sicherung wiederhergestellt (nachgelesen: lesbar, mit Kennung oder Merkwort)."
                else
                    echo "<WARNING> Die Sicherung wurde kopiert, liess sich danach aber nicht als Konfiguration lesen."
                fi
            else
                rm -f "$CF.neu"
                echo "<WARNING> Die Sicherung $BK liess sich nicht zurueckspielen - sie bleibt liegen."
            fi
        elif [ "$LAGE_BK" -eq 3 ]; then
            echo "<WARNING> Die Sicherung liess sich nicht pruefen (php oder bw_lib.php fehlt) - sie wurde nicht zurueckgespielt."
        elif [ -f "$BK" ]; then
            echo "<INFO> Die Sicherung $BK traegt weder Kennung noch Merkwort oder ist unlesbar - sie wurde nicht zurueckgespielt."
        fi
    fi
elif [ -f "$BK" ]; then
    # Neuinstallation (keine Marke): nichts einspielen, Entscheidung 1.
    # preinstall.sh legt eine liegengebliebene Zweitschrift beiseite; liegt
    # trotzdem eine da, liess sie sich nicht verschieben.
    echo "<WARNING> Neuinstallation: $BK stammt aus einer frueheren Installation und wird NICHT eingespielt - bitte von Hand entfernen."
fi
# I3: am Ende noch einmal, gleich welcher Weg oben lief.
chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

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

# Die Erstanleitung nur, wenn danach keine eingerichtete Konfiguration
# vorliegt - entschieden an der Kennung des Bausteins, nicht an einem blossen
# Merkwort (das entsteht beim ersten Oeffnen der Oberflaeche). Bis 0.9.19
# stand sie nach JEDEM Update da (Regeln/06, "Nach einer Aktualisierung darf
# der Schlusstext nicht zur Erstinstallation raten"; Fall Z7).
# Die Kennung wird mit dem JSON-Leser geprueft, wie oben (I2).
bw_kennung() {
    [ -f "$1" ] && [ -n "$BW_LIB" ] && command -v php >/dev/null 2>&1 || return 1
    php -r 'require $argv[1]; $d = json_decode((string) @file_get_contents($argv[2]), true); exit((is_array($d) && isset($d["uuid"]) && is_string($d["uuid"]) && bw_kennung_sauber($d["uuid"]) !== "") ? 0 : 1);' "$BW_LIB" "$1" >/dev/null 2>&1
}
if bw_kennung "$CF"; then
    echo "<OK> Installation abgeschlossen - die Einstellungen sind uebernommen, es ist nichts weiter zu tun."
    exit 0
fi
echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: Miniserver waehlen, Kennung des"
echo "<INFO>     Bausteins pruefen und EINSCHALTEN. Ab Werk ist es aus."
echo "<INFO>  2. Reiter Test: 'Befehl jetzt senden'. Fahren danach Rollladen"
echo "<INFO>     oder wird das A in der App gruen, traegt der Weg."
exit 0
