# LoxBerry-Plugin „Beschattungswächter"

Version 0.9.12

Drückt in einem einstellbaren Abstand das **A** — den Knopf, der in Loxone die
Sonnenstandsautomatik einschaltet und den sonst nur ein Mensch drücken kann.

* Schickt `/dev/sps/io/<Kennung>/auto` an den Miniserver, wie die App.
* Holt die Zugangsdaten aus der **zentralen LoxBerry-Konfiguration** — kein
  Passwort im Plugin, keines in der Loxone-Projektdatei.
* Nur im eingestellten Zeitfenster und höchstens einmal je Abstand.
* Bis zu **sechs Ziele** — ein Zentralbaustein oder einzelne Rollläden.
* **Rückweg nach Loxone:** ein Endpunkt im unangemeldeten Bereich, den der
  Miniserver ohne Zugangsdaten abfragen kann, geschützt durch ein Merkwort.
* Wahlweise zusätzlich über **MQTT**, mit Lebenszeichen bei jedem Durchgang.
* Misst auf Wunsch die **Wirkung** (`autoActive`) statt nur des Rückgabewerts.
* Meldet sich beim **Healthcheck** von LoxBerry.
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

Der Cron läuft **alle fünf Minuten**; die Entscheidung, ob wirklich gesendet
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

## Einstellungen sichern und zurückspielen

Zwei Knöpfe im Reiter *Einstellungen*. Der Zweck ist der **Umzug auf einen
zweiten LoxBerry**, nicht die Sicherung gegen Verlust — dafür legt das Plugin
bei jedem Speichern ohnehin eine Zweitschrift neben seinen Konfigurationsordner,
und die überlebt ein Update.

Die Datei ist JSON, trägt einen lesbaren Kopf mit Datum und Fassung und enthält
**alle** Einstellungen — auch die, die gerade auf ihrem Vorgabewert stehen.

**Sie enthält das Merkwort des Endpunkts** — und das ist Absicht. Ohne dieses
eine Feld wären nach dem Zurückspielen alle Einstellungen richtig und das
Plugin trotzdem unbrauchbar: jede in Loxone eingetragene Adresse trägt das
Merkwort, und der Miniserver bekäme auf jede Abfrage eine 403. Der Preis steht
dafür in der Oberfläche: **wer die Datei weitergibt, gibt den Schlüssel zu
diesem Endpunkt weiter.**

Das Kennwort des **Miniservers** steht nicht darin. Es liegt in der zentralen
LoxBerry-Konfiguration, und dieses Plugin liest es dort, zeigt es nicht an und
schreibt es nirgends hin.

Beim Zurückspielen gilt: eine halb gültige Datei ändert **gar nichts**.
Unbekannte Schlüssel und unzulässige Werte werden benannt, alle auf einmal, und
der bisherige Stand bleibt unangetastet.

## Der Rückweg: Loxone erfährt, ob der Wächter lebt

Bis 0.9.10 war das Plugin eine **Einbahnstraße**. Es schickte Befehle, und
Loxone erfuhr nie, ob es noch läuft — ein virtueller Eingang behält seinen
letzten Wert, ein toter Wächter sah aus wie ein ruhiges Haus.

Seit 0.9.11 gibt es einen Endpunkt im unangemeldeten Bereich:

```
/plugins/beschattungswaechter/index.php?token=<MERKWORT>&aktion=status
```

Er antwortet mit einer Zeile aus benannten Feldern (`OK`, `AKTIV`, `FENSTER`,
`ZIELE`, `GESENDET`, `FEHLER`, `CODE`, `ALTER`, `ZAEHLER`, `SCHARF`,
`AUTOMATIKEN`). Der Reiter *Einbindung in Loxone* führt in sieben Schritten
durch die Einrichtung, zeigt zu jedem Feld die Befehlserkennung — mit dem
**führenden Semikolon**, ohne das Loxone den falschen Treffer nimmt — und
liefert zwei fertige Vorlagen zum Einlesen.

Vier Festlegungen, jede aus einem Vorfall dieser Plugin-Sammlung:

1. **Der Endpunkt legt nichts an.** Wer sich nicht ausweisen kann, hinterlässt
   keine Datei — auch keine harmlose.
2. **Der leere Fall wird vor dem Vergleich abgefangen.** `hash_equals('', '')`
   ist in PHP `true`; ohne diese Prüfung stünde der Endpunkt offen, solange
   kein Merkwort gesetzt ist.
3. **Jeder Parameter geht durch `is_string()`.** `?token[]=x` ergäbe sonst
   HTTP 500 mit leerem Rumpf — der Miniserver bekäme gar nichts zu lesen.
4. **Jeder Weg schreibt eine Protokollzeile**, auch die Abweisung. Das Merkwort
   selbst steht nie darin.

Neben `status` kennt er `json`, `jetzt` (setzt den Befehl sofort ab, ohne
Rücksicht auf Zeitfenster und Abstand) und `pruefen`.

## MQTT

Über HTTP *holt* der Miniserver die Werte ab; über MQTT *schickt* das Plugin sie
von sich aus — bei **jedem** Durchgang, auch wenn nichts zu senden war. Sonst
ist ein toter Wächter von einem ruhigen Haus nicht zu unterscheiden.

Der MQTT-Gateway gehört seit LoxBerry 3 zum System. Bis zu seiner Fassung 1 muss
das Abonnement dort von Hand eingetragen werden, ab Fassung 2 trägt der Gateway
es selbst ein und schaltet das Eingabefeld ab. Der Reiter *MQTT* liest die
Fassung aus `config/system/general.json` und zeigt genau den Satz, der zur
gemessenen Fassung gehört — und wenn sie nicht lesbar ist, **beide**.

## Die Wirkung messen, nicht den Rückgabewert

`HTTP 200` heißt nur: der Miniserver hat den Befehl *angenommen*. Ob danach eine
Automatik wirklich scharf ist, steht in einem anderen Zustand — `autoActive`.

Das Plugin kann ihn über `LoxAPP3.json` für jeden Baustein vom Typ *Jalousie*
abfragen und zählen. Am 28.08.2026 an einer Anlage mit 25 Jalousien gemessen:
13 mit `autoActive = 1`, 12 mit `0`. Der Zentralbaustein `CentralJalousie` führt
diesen Zustand **nicht** und wird deshalb nicht mitgezählt.

Die Messung ist ab Werk **aus**: sie fragt je Jalousie mehrere Zustände ab, und
auf einer großen Anlage ist das spürbare Last. Eingeschaltet läuft sie höchstens
einmal je Viertelstunde.

## Das Protokoll

Aufgeschrieben wird der **erste Befehl des Tages** und **jeder Fehler** — nicht
jeder Lauf. Bei einem Fünfminutentakt ergäbe das rund dreihundert Zeilen am Tag,
in denen die eine wichtige untergeht.

`log/plugins` liegt auf einer Ramdisk: das Protokoll übersteht keinen Neustart.

## Voraussetzungen

* LoxBerry ab 3.0.0 mit eingerichtetem Miniserver
* PHP 7.4 oder neuer (geprüft gegen 7.4 und 8.4)

Reines PHP, keine Nachinstallation, keine Internetverbindung. Das Plugin
spricht ausschließlich mit dem Miniserver im eigenen Netz.

## Fassung 0.9.12 — der Stat-Zwischenspeicher
Die Protokollkappung (262 144 Byte) stand in
`webfrontend/html/bw_lib.php:505`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
