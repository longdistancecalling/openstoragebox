# OpenStorageBox – Funktionsübersicht

Version 12 · Stand 14.10.2026 · DRK KV Eisenach

PHP 8.3 + MariaDB 11 im Docker-Stack, 32 Seiten, keine externen
Abhängigkeiten.

---

## Bestand

- **Artikel** mit Name, Inventarnummer, EAN, Kategorie, Notiz
- **Lagerorte** als feste Hierarchie **Lager → Regal → Fach**, unter
  „Verwaltung → Lagerorte" gepflegt. Ein Artikel liegt wahlweise in einer
  Kiste, direkt in einem Fach, an einem freien Standort (Zelte, Aggregate,
  Paletten) oder noch nirgends
- **Kisten** stehen in einem Fach und sind systemweit eindeutig; Umlagern
  ist ein Vorgang, alle Artikel darin wandern mit. Ein Regal umbenennen
  ändert den Ort überall automatisch mit
- **Verpackungseinheiten**: 8 Kisten à 20 Flaschen + 7 lose = 167
  Flaschen. Bei Einzelentnahme wird automatisch ein Gebinde
  aufgebrochen, bei Zugang wieder zusammengesetzt
- **Sollbestand** je Artikel, optional – darunter erscheint der Artikel
  unter Bedarf
- **Benutzerdefinierte Felder** je Kategorie: Größe bei Kleidung,
  Leistung bei Elektro, Löschmittel bei Feuerlöschern. Fünf Typen,
  wahlweise Pflichtfeld
- **Fälligkeiten**: Ablaufdatum oder nächster Prüftermin, Vorwarnzeit
  einstellbar
- **MHD-Chargen**: gleiche Lebensmittel mit verschiedenen
  Mindesthaltbarkeitsdaten in einem Artikel – MHD beim Einbuchen,
  Chargenwahl bei der Entnahme, frühestes MHD als Fälligkeit

## Buchen

- **Entnahme** mit fünf Gründen: Einsatz, Verschleiß, Defekt,
  Abgelaufen, Verliehen
- **Rückgaben** zu Leihe und Einsatz, auch in Teilen – offene Leihen
  stehen mit Tageszähler in einer eigenen Liste, beim Einsatz zeigt das
  Protokoll, was zurückkam und was verbraucht wurde
- **Wareneingang** als Gegenstück: Beschaffung, Spende, Rücklauf,
  Korrektur
- **Bewegungsprotokoll** über Zu- und Abgänge, filterbar nach Richtung,
  Grund, Kategorie und Zeitraum
- Jede Buchung läuft in einer Transaktion mit Zeilensperre und hält
  fest, wer sie erfasst hat
- **Terminal als Startseite**: nach der Anmeldung geht es direkt zu Entnahme,
  Einlagern, Übersicht und Bedarf; der Terminal-Knopf steht auf jeder Seite
- **Darstellung pro Gerät**: 16 Farbdesigns in Gruppen – Hell, Dunkel;
  Hoher Kontrast, Rot-Grün-sicher; Nachtsicht (Rotlicht), E-Ink; Rotes Plus,
  Rettungsdienst, Feuerwehr, Blaues Zahnrad, Wellenwacht; Wald, Lagerbuch, Blaupause,
  Phosphor, Warnweste –, Schrift Standard, OpenDyslexic oder Lexend (für
  LRS), frei kombinierbar

## Scannen

Ein USB-Handscanner reicht – keine Bibliothek, keine Kamerarechte. Für ein
Tablet an der Wand gibt es zusätzlich ein **Touch-Terminal** mit vier
großen Bereichen – Entnahme, Einlagern, Übersicht, Bedarf – und
Auswahl per Fingertipp statt Klapplisten. Erkannt werden:

| Code | führt zu |
|---|---|
| `K:K001` | Kiste mit Inhaltsliste und Buchungsknöpfen |
| `A:A-00042` | Artikel mit Bestand und Buchung |
| `R:Keller\|R1` | alle Kisten eines Regals |
| `E:E-00042` | Buchung zu einem Papierbeleg, bei Leihe und Einsatz mit Rückgabe |
| EAN | Hersteller-Barcode |

Mit hinterlegter Adresse enthalten die QR-Codes eine vollständige URL –
ein Scan mit der Handykamera öffnet direkt die Buchungsseite.

## Inventur

- Snapshot beim Start: Name, Lagerort und Gebindeangaben werden kopiert,
  damit ein abgeschlossener Beleg lesbar bleibt
- Zählliste zum Ausdrucken, nach Lagerort gruppiert
- Bei Gebinden zwei Spalten: volle Gebinde und lose Stücke
- Differenz in Einzelstücken – eine fehlende Kiste à 24 erscheint als
  −24, nicht als −1
- Abschluss übernimmt die Zählwerte wahlweise in den Bestand

## Drucken

| Ausdruck | Format | Zweck |
|---|---|---|
| Lagerübersicht | A4, zweispaltig | Aushang an der Tür, vier Gliederungen |
| Regalschild | A5 quer | Stirnseite des Regals, mit QR |
| Kistenetikett | A5 quer | auf oder in der Kiste, mit QR |
| Klebeetiketten | A4, 3 Raster | selbstklebend, 24/12/40 je Bogen, mit QR |
| Entnahmeschein | A5 quer | Beleg, bei Leihe mit Unterschriftsfeldern |
| Blanko-Scheine | A5 quer | Vorrat fürs Lager, handschriftlich |
| Zählliste | A4 | Inventur |
| Artikelliste | A4 | alphabetisch mit Lagerort |

Alle Ausdrucke tragen den Organisationsnamen. Kisten- und Regalschild
teilen dieselbe Bildsprache.

## Daten

- **CSV-Export**: Artikelliste, Kisteninhalt, Kistenverzeichnis,
  Bewegungen – Semikolon und BOM, öffnet in Excel ohne Nacharbeit
- **CSV-Import** für Artikel mit Vorschau und Zeilenprüfung; vorhandene
  Inventarnummern werden aktualisiert, unbekannte Kisten angelegt
- **JSON-Gesamtexport** für Umzug oder Sicherung
- **JSON-Gesamtimport** als Alles-oder-nichts-Vorgang
- **backup.sh / restore.sh** für die Datenbank, getestet inklusive
  vollständiger Wiederherstellung

## Benutzer und Rechte

| Rolle | darf |
|---|---|
| Leser | alles ansehen, alle Exporte |
| Bearbeiter | zusätzlich anlegen, ändern, buchen, zählen, CSV-Import |
| Administrator | zusätzlich löschen, Inventur abschließen, Benutzer und Einstellungen |

Die Prüfung ist serverseitig in jedem Handler. Passwörter mit bcrypt,
Sperre nach fünf Fehlversuchen, kein Standardpasswort – der erste
Administrator wird beim ersten Aufruf angelegt.

## Einstellungen

Organisation, Adresse der Anwendung und Vorwarnzeit für Fälligkeiten
sind in der Oberfläche änderbar, nicht im Quelltext.

---

## Was noch fehlt

Siehe ROADMAP.md. Als Nächstes stehen dort die Einsatzauswertung und die
Inventur je MHD-Charge.
