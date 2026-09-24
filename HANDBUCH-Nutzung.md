# OpenStorageBox – Handbuch für die tägliche Nutzung

DRK KV Eisenach · Stand Version 9

Dieses Heft erklärt, wie man mit der Lagerverwaltung arbeitet. Es ist für
alle gedacht, die Material ein- und ausbuchen, suchen oder zählen. Für
Einrichtung, Sicherung und Rechteverwaltung gibt es ein eigenes
Administrationshandbuch.

---

## 1. Anmelden

Die Lagerverwaltung läuft im Browser. Adresse vom Administrator erfragen –
meist etwas wie `http://<rechnername>:8080`.

Auf der Anmeldeseite Benutzernamen und Passwort eingeben. Wer sein Passwort
ändern will, klickt nach der Anmeldung oben rechts auf den eigenen Namen.

**Fünf Fehlversuche** sperren das Konto für eine Viertelstunde. Das schützt
gegen fremdes Ausprobieren. Wer sich wirklich nicht mehr erinnert, wendet
sich an einen Administrator – der kann ein neues Passwort setzen.

### Was darf ich?

Es gibt drei Stufen. Welche man hat, sieht man oben rechts neben dem Namen.

- **Leser** – alles ansehen, suchen, drucken, exportieren. Nichts ändern.
- **Bearbeiter** – zusätzlich Artikel anlegen und ändern, ein- und
  ausbuchen, Inventur zählen.
- **Administrator** – zusätzlich löschen, Benutzer und Einstellungen
  verwalten.

Wer einen Knopf nicht sieht oder eine Seite nicht öffnen kann, hat dafür
nicht die nötige Stufe. Das ist kein Fehler.

---

## 2. Sich zurechtfinden

Oben läuft die Navigationsleiste:

- **Artikel** – die Bestandsliste, der Ausgangspunkt für fast alles
- **Scannen** – buchen per Barcode oder QR-Code
- **Bewegungen** – wer hat wann was gebucht
- **Bedarf** – was fehlt, was ist abgelaufen, was läuft bald ab
- **Kisten** – die Lagerplätze
- **Inventur** – zählen und abgleichen
- **Export** – alles zum Drucken und Herunterladen
- **Verwaltung** – Stammdaten und Einstellungen (je nach Stufe)

Ganz rechts steht immer ein roter **Entnahme**-Knopf. Er ist im Einsatz
gedacht, wenn es schnell gehen muss – er führt mit einem Klick zum
Ausbuchen, egal wo man gerade ist. Tastenkürzel: Alt und E zusammen.

---

## 3. Einen Artikel finden

Auf der Seite **Artikel** oben das Suchfeld nutzen. Es durchsucht Nummer,
Barcode, Name, Kiste, Lagerort, Notiz und die Zusatzangaben gleichzeitig –
ein Wort genügt meist.

Daneben lässt sich nach Kategorie, Lagerort und Sollbestand filtern und die
Sortierung umstellen. Ein Klick auf eine Spaltenüberschrift sortiert
danach; ein zweiter Klick dreht die Richtung um.

Der **Lagerort** steht in jeder Zeile: die schwarze Kistennummer, dahinter
Lager, Regal und Fach. Damit findet man das Material im Regal wieder.

---

## 4. Material entnehmen

Es gibt zwei Wege – beide landen am selben Ziel.

### Über die Liste

Bei einem Artikel auf **Entnehmen** klicken, oder den roten Knopf oben
nutzen und den Artikel suchen. Dann:

1. **Menge** eingeben.
2. Bei Ware in Kisten die **Einheit** wählen – ganze Kisten oder einzelne
   Flaschen. Das Feld erscheint nur, wenn der Artikel in Gebinden lagert.
3. **Grund** wählen (siehe unten).
4. **Buchen**.

Der Bestand sinkt sofort, und die Buchung steht im Protokoll.

### Per Scan

Auf **Scannen** gehen und den Code einlesen – mit einem USB-Handscanner
einfach draufhalten, er tippt wie eine Tastatur. Erkannt werden:

- der **QR-Code auf einer Kiste** – zeigt den ganzen Kisteninhalt zum
  direkten Buchen
- der **QR-Code auf einem Artikel**
- der **Barcode (EAN)** vom Hersteller

Beim Scannen wird immer auf den eigenen Namen und das heutige Datum
gebucht. Ein einzelner Scan verändert noch nichts – erst der Klick auf
Entnehmen oder Hinzufügen bucht.

### Die fünf Gründe

| Grund | Bedeutung | kommt zurück |
|---|---|---|
| Einsatz | im Einsatz verbraucht | nein |
| Verschleiß | abgenutzt, ausgesondert | nein |
| Defekt | kaputt, entsorgt | nein |
| Abgelaufen | Haltbarkeit überschritten | nein |
| Verliehen | an anderen Ortsverband oder Person | **ja** |

**Verliehen ist der einzige Grund, bei dem Material zurückkommt.** Bei einer
Leihe ist das Personenfeld Pflicht – man muss wissen, wer das Zeug hat. Die
Buchung bleibt offen, bis die Rückgabe bestätigt wird.

---

## 5. Material einlagern

Wenn Nachschub kommt oder Material aus dem Einsatz zurückkehrt: bei einem
Artikel auf **Einbuchen**, oder über *Bewegungen → Zugang buchen*. Anders
als beim Entnehmen sind hier auch Artikel mit Bestand null wählbar – genau
die will man ja auffüllen.

Gründe: Beschaffung, Spende, Rücklauf, Korrektur. **Korrektur** ist für den
Fall gedacht, dass im Regal mehr liegt als im System steht. Wenn das oft
vorkommt, ist meist eine Inventur der bessere Weg.

---

## 6. Eine Leihe zurücknehmen

Auf **Bewegungen** stehen ganz oben die *offenen Leihen* mit einem
Tageszähler. Kommt Material zurück, dort auf **Zurück gebucht** klicken –
der Bestand steigt wieder, und die Leihe wird geschlossen.

Wer den Papier-Leihschein in der Hand hat, kann auch dessen QR-Code
scannen; dann erscheint die Buchung direkt mit dem Rückgabe-Knopf.

---

## 7. Einen Artikel anlegen oder ändern

*(nur Bearbeiter und Administrator)*

Auf **Artikel → Artikel anlegen**. Pflicht ist nur der Name. Alles andere
ist optional:

- **Inventarnummer** – leer lassen vergibt automatisch eine (A-00042).
- **EAN** – der Hersteller-Barcode, ermöglicht das Scannen.
- **Lagerort** – vier Möglichkeiten: in einer **Kiste**, **direkt in einem
  Fach** (ohne Kiste), an einem **freien Standort** (für Zelte, Aggregate,
  Paletten) oder **noch keiner**. Kisten und Fächer stammen aus der
  Lagerort-Verwaltung (siehe unten); fehlt das passende, legt eine
  Bearbeiterin oder ein Admin es dort an.
- **Verpackungseinheit** – für Ware in Gebinden, siehe nächster Abschnitt.
- **Sollbestand** – Mindestmenge; fällt der Bestand darunter, erscheint der
  Artikel unter *Bedarf*. Leer lassen, wenn nicht überwacht werden soll.
- **Datum** – Ablauf- oder Prüfdatum.
- **Zusatzangaben** – kategorieabhängige Felder wie Größe oder Leistung.

Wer viele Artikel hintereinander erfasst, nutzt **Speichern & weiter**: Das
sichert und öffnet gleich ein leeres Formular, wobei Kiste und Kategorie der
letzten Eingabe stehen bleiben.

---

## 8. Lagerorte aufbauen

Bevor Kisten und Artikel einen Platz bekommen, wird die Struktur des Lagers
angelegt: **Lager → Regal → Fach**. Das macht eine Bearbeiterin oder ein
Admin einmalig unter **Verwaltung → Lagerorte**.

Die Seite hat drei Spalten nebeneinander. Links wählt man ein Lager, dann
erscheinen in der Mitte dessen Regale, rechts die Fächer des gewählten
Regals. Am Fuß jeder Spalte legt ein kleines Feld einen neuen Eintrag an –
erst das Lager (z. B. „Keller"), dann darin ein Regal (z. B. „R1"), dann
darin ein Fach (z. B. „F1"). Bei jedem Fach steht, wie viele Kisten und
Artikel darin liegen.

Ein Lager, Regal oder Fach lässt sich jederzeit **umbenennen** – der neue
Name erscheint sofort überall, auch an allen Kisten und Artikeln, die darin
liegen. **Löschen** kann nur ein Admin, und nur solange nichts mehr darin
steht; sonst kommt ein Hinweis, erst umzuräumen.

Kisten und Artikel wählen ihr Fach danach aus einer Liste aus, statt es
abzutippen. Das verhindert Tippfehler wie ein zweites „Kelller" und erlaubt
leere Regale, die trotzdem schon ein Regalschild bekommen können.

---

## 9. Verpackungseinheiten verstehen

Getränke, Verbandmaterial und Ähnliches kommen in Gebinden. Das System
zählt sie so, wie sie im Regal stehen:

```
8 Kisten  +  7 lose Flaschen  =  167 Flaschen
```

- **Kisten** sind volle Gebinde.
- **Lose** sind Einzelstücke aus einer angebrochenen Kiste.

Beim Entnehmen einzelner Flaschen bricht das System bei Bedarf automatisch
eine Kiste auf – genau wie man es im Regal auch täte. Beim Einbuchen werden
aus genug losen Stücken wieder volle Kisten.

Die Übersicht **Gebinde** (Knopf oben in der Artikelliste) zeigt, wie viele
Gebinde und wie viele Einzelstücke insgesamt da sind.

---

## 10. Inventur

*(Zählen: Bearbeiter · Abschließen: Administrator)*

Eine Inventur ist der Abgleich zwischen System und Regal.

1. **Starten** – auf *Inventur → Inventur starten*. Das System merkt sich
   den aktuellen Bestand als Soll.
2. **Zählliste drucken** – nach Lagerort gruppiert, zum Mitnehmen ins Lager.
   Bei Gebinden gibt es zwei Spalten: volle Gebinde und lose Stücke.
3. **Zählen** – die gezählten Mengen eintragen, am Rechner oder Tablet. Die
   Ortsleiste oben zeigt, wo noch etwas offen ist.
4. **Abschließen** – ein Administrator übernimmt die Zählwerte in den
   Bestand oder lässt sie nur als Bericht stehen.

Wichtig beim Zählen: Ein **leeres Feld** heißt „noch nicht gezählt". Für
„nichts mehr da" trägt man eine **0** ein.

---

## 11. Was ist zu tun? – die Seite Bedarf

**Bedarf** beantwortet die drei Fragen vor einer Beschaffung auf einen Blick:

- **Überfällig** – Prüftermine sind verstrichen oder Ware ist abgelaufen.
- **Unter Sollbestand** – es ist weniger da als vorgesehen. Bei Gebinden
  steht dabei, wie viele Kisten nachzubestellen sind.
- **Bald fällig** – in den nächsten Wochen läuft etwas ab.

Von hier lässt sich direkt einbuchen oder der Artikel bearbeiten.

---

## 12. Drucken

Unter **Export** liegen alle Ausdrucke:

- **Lagerübersicht** – der komplette Bestand für einen Aushang an der Tür.
- **Regalschilder** – ein Blatt je Regal für die Stirnseite.
- **Kistenetiketten** – zum Aufkleben, mit QR-Code und Inhaltsliste.
- **Klebeetiketten** – kleine Etiketten mit QR für Artikel und Kisten.
- **Scheine** – Entnahme-, Leih- und Einlagerungsbeleg, auch blanko auf
  Vorrat.

**Beim Drucken von Etiketten** im Druckdialog die Skalierung auf 100 Prozent
stellen. Sonst verrutscht das Raster und keine Zeile trifft mehr. Erst auf
Normalpapier testen und gegen den Etikettenbogen halten.

---

## 13. QR-Codes im Einsatz

Auf Kisten, Regalen und Etiketten sind QR-Codes. Mit dem Handy scannen
(normale Kamera-App genügt, wenn die Adresse eingerichtet ist) öffnet sich
direkt die passende Seite:

- **Kiste** → Inhalt mit Buchungsknöpfen
- **Regal** → alle Kisten des Regals
- **Artikel** → Bestand und Buchung

So kann man am Regal stehen, scannen und buchen, ohne zu tippen.

---

## 14. Häufige Fragen

**Ich habe mich verbucht.** Einfach die Gegenbuchung machen: falsch
entnommen → einbuchen, falsch eingebucht → entnehmen. Das Protokoll zeigt
beide Vorgänge, das ist ehrlicher als ein stilles Löschen.

**Der Bestand stimmt nicht mit dem Regal überein.** Kleine Abweichung: mit
Grund *Korrektur* einbuchen oder ausbuchen. Größere oder regelmäßige
Abweichung: eine Inventur ansetzen.

**Ein Artikel liegt in zwei Kisten.** Das ist erlaubt – zwei Zeilen mit
gleichem Namen, jede an ihrem Ort. So verteilt man Material auf mehrere
Lager.

**Ein Ausdruck zeigt einen alten Namen oder Stand.** Ausdrucke sind
Momentaufnahmen. Sie veralten mit jeder Buchung – deshalb steht das Datum
darauf. Für den aktuellen Stand neu drucken.

**Etwas fehlt oder klemmt.** An einen Administrator wenden. Wer einen echten
Fehler vermutet, sollte notieren, was er getan hat, bevor es auftrat – das
hilft bei der Suche enorm.
