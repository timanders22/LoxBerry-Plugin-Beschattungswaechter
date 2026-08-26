# LoxBerry-Plugin „Beschattungswächter"

Version 0.9.5

Drückt in einem einstellbaren Abstand das **A** — den Knopf, der in Loxone die
Sonnenstandsautomatik einschaltet und den sonst nur ein Mensch drücken kann.

* Schickt `/dev/sps/io/<Kennung>/auto` an den Miniserver, wie die App.
* Holt die Zugangsdaten aus der **zentralen LoxBerry-Konfiguration** — kein
  Passwort im Plugin, keines in der Loxone-Projektdatei.
* Nur im eingestellten Zeitfenster und höchstens einmal je Abstand.
* Entscheidet **nicht**, ob beschattet wird. Das bleibt Sache der Loxone-Logik.

## Woraus es entstanden ist

Am 24.08.2026 stand in diesem Haus die Sonne im Osten, die Beschattungsfreigabe
war erteilt, alle achtzehn Räume forderten Beschattung — und **keine einzige der
25 Rollladenautomatiken war scharf**. Ein Druck auf das A im Zentralbaustein,
und sechs Rollläden fuhren sofort auf 80 Prozent.

Nachgemessen am Baustein selbst: `Sps` (Start) = Ein, `DisSp` (Sperre) = Aus,
ein frischer `Spr`-Impuls um 12:10 — und um 12:13 immer noch nichts.

## Zwei Eigenarten, die zusammen den Fehler ergeben

**Erstens: die Automatik lässt sich aus der Logik nicht einschalten.** Der
Baustein *Automatische Beschattung* hat `Sps` (Start), `DisSp` (Sperre) und
`Spr` (Reaktivierung nach Handbedienung) — aber keinen Eingang, der eine
abgeschaltete Automatik zurückholt. Das kann nur der Befehl hinter dem A.

**Zweitens: die Schaltuhr schaltet sie jeden Morgen ab.** Sie fährt die
Rollläden über den Eingang `Co` (Complete open) hoch, und eine Bedienung über
`Co` gilt für Loxone als **Handbedienung** — die deaktiviert die
Sonnenstandsautomatik für den Rest des Tages.

Das Haus schaltet sich also täglich seine eigene Beschattung aus. Es fällt nur
nicht auf, solange die Freigabe ohnehin selten kommt.

## Warum auf dem LoxBerry und nicht in Loxone

Derselbe Befehl ließe sich auch mit einem virtuellen Ausgang aus Loxone heraus
schicken. Dann müssten aber **Benutzer und Passwort in der Adresse stehen** —
in der Projektdatei, wo bisher nur Plugin-Tokens liegen.

Der LoxBerry kennt die Zugangsdaten bereits; jedes Plugin greift auf dieselbe
Stelle zu. Dieses Plugin liest sie dort, zeigt sie nicht an und speichert sie
nicht. Das ist der einzige Grund für den Umweg.

## Der Abstand ist eine Abwägung

Der Befehl holt auch eine Automatik zurück, die jemand **absichtlich**
abgeschaltet hat. Wer einen Rollladen in der Sonne hochfährt, hätte ihn sonst
kurz darauf wieder unten.

Sechzig Minuten sind lang genug, dass das im Alltag nicht stört, und kurz
genug, dass eine Fassade, deren Sonne erst mittags kommt, den Tag nicht
verpasst. Die Zahl steht an einer einzigen Stelle und lässt sich zwischen 5 und
720 Minuten setzen.

Der Cron läuft im Viertelstundentakt; die Entscheidung, ob wirklich gesendet
wird, trifft das Plugin. So muss man beim Ändern des Abstands keine Datei
verschieben.

## Einrichten

1. **Reiter Einstellungen:** Miniserver wählen, Kennung des Bausteins
   eintragen, einschalten. Ab Werk ist das Plugin **aus**.
   Die Kennung ist die UUID des Zentralbausteins *Beschattung* — in der
   Projektdatei das Attribut `U`, in der App Teil der Adresse.
2. **Reiter Test:** *Befehl jetzt senden*. Fahren daraufhin Rollläden oder wird
   das A in der App grün, trägt der Weg.
3. Ab dann übernimmt der Cron.

Am Zentralbaustein heißt der Befehl `auto`, an einem einzelnen Rollladen
`autoshade/1`. Beides schaltet ein; `noauto` und `autoshade/0` schalten aus.

## Das Protokoll

Aufgeschrieben wird der **erste Befehl des Tages** und **jeder Fehler** — nicht
jeder Lauf. Ein Viertelstundentakt erzeugte sonst hundert Zeilen am Tag, in
denen die eine wichtige untergeht.

## Voraussetzungen

* LoxBerry ab 3.0.0 mit eingerichtetem Miniserver
* PHP 7.4 oder neuer (geprüft gegen 7.4 und 8.4)

Reines PHP, keine Nachinstallation, keine Internetverbindung. Das Plugin
spricht ausschließlich mit dem Miniserver im eigenen Netz.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
