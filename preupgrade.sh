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
    echo "<WARNING> config/system/general.json. Es wurde nichts gesichert."
    exit 1
fi
CF="$BASE/config/plugins/$PFOLDER/beschattung.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"

# ---------------------------------------------------------------------------
# ZUERST die Marke "Aktualisierung laeuft" - vor jeder Sicherung (I1,
# Entscheidung 1 vom 29.09.2026; Bauform AudiConnect 0.9.22).
#
# Sie ist das einzige Zeichen, an dem preinstall.sh und postinstall.sh ein
# Update von einer Neuinstallation unterscheiden - kein Altersvergleich. Bei
# einer Neuinstallation legt preinstall.sh eine liegengebliebene Zweitschrift
# beiseite; bei einem Update spielt postinstall.sh sie zurueck. Die Marke liegt
# NEBEN dem Datenordner: purge_installation loescht den Ordner, nicht den
# Nachbarn mit dem Punkt. Solange sie liegt, meldet der Healthcheck einen
# Hinweis statt eines Fehlers (I4). postinstall.sh raeumt sie ab; ohne sie
# hielte die Installation das Update fuer eine Neuinstallation - deshalb ist
# ein Fehlschlag hier ein Abbruchgrund.
# ---------------------------------------------------------------------------
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Marke fuer die Aktualisierung gesetzt."
else
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte die Installation das Update fuer eine Neuinstallation und"
    echo "<FAIL> legte die Einstellungen beiseite. Das Upgrade wird abgebrochen."
    exit 2
fi

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

# Gesichert wird nach INHALT, und die Sicherung entsteht neben ihrem Platz,
# wird geprueft und erst dann umbenannt (Regeln/06). Die Sicherung ist
# zugleich die Zweitschrift, aus der bw_lib.php heilt. Ein Rest aus einem
# frueheren, abgebrochenen Lauf faellt zuerst (Entscheidung 1: "preupgrade.sh
# raeumt einen alten Bestand weg, bevor es einen neuen anlegt"). Die Kopie
# entsteht mit umask 077 - sie traegt das Merkwort und ist von Anfang an 0600.
rm -f "$BK.neu" 2>/dev/null
bw_lage "$CF"
LAGE_CF=$?
case "$LAGE_CF" in
    0)
        if ( umask 077 && cp "$CF" "$BK.neu" ) 2>/dev/null && chmod 600 "$BK.neu" 2>/dev/null \
           && cmp -s "$CF" "$BK.neu" && { bw_lage "$BK.neu"; [ $? -eq 0 ]; } \
           && mv -f "$BK.neu" "$BK"; then
            echo "<OK> Konfiguration gesichert (nachgelesen: lesbar, mit Kennung oder Merkwort)."
        else
            rm -f "$BK.neu"
            echo "<WARNING> Die Konfiguration liess sich nicht sichern; eine vorhandene Sicherung bleibt unveraendert."
        fi
        ;;
    1)
        bw_lage "$BK"
        if [ $? -eq 0 ]; then
            echo "<WARNING> Die Konfiguration traegt weder Kennung noch Merkwort. Die Sicherung"
            echo "<WARNING> $BK traegt Inhalt und bleibt unveraendert."
        else
            echo "<INFO> Die Konfiguration traegt weder Kennung noch Merkwort - nichts zu sichern."
        fi
        ;;
    2)
        if [ -f "$CF" ]; then
            # UNLESBAR: sie ueberschreibt NIE die Zweitschrift. Sie geht als
            # .kaputt NEBEN den Konfigordner - im Ordner raeumte der Installer
            # sie gleich mit ab. Die Bibliothek liest diese Datei nie.
            ( umask 077 && cp "$CF" "$BK.kaputt" ) 2>/dev/null && chmod 600 "$BK.kaputt" 2>/dev/null
            echo "<WARNING> Die Konfiguration ist kein lesbares JSON. Die Sicherung $BK bleibt"
            echo "<WARNING> unveraendert; die unlesbare Datei liegt als $BK.kaputt daneben."
        else
            echo "<INFO> Es gibt keine Konfiguration - nichts zu sichern."
        fi
        ;;
    *)
        echo "<WARNING> Die Konfiguration liess sich nicht pruefen (php oder bw_lib.php fehlt)."
        echo "<WARNING> Die Sicherung $BK bleibt unveraendert."
        ;;
esac
echo "<OK> preupgrade abgeschlossen."
exit 0
