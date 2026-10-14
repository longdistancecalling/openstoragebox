# Roadmap

Stand: 14.10.2026 · aktuelle Version v11

Ein größerer offener Punkt (Chargen mit MHD), dazu die Einsatzauswertung
und Kleinigkeiten. Dringend ist die Lücke im JSON-Gesamtexport (Punkt 3):
Beim Umzug gehen dort heute Daten verloren.

---

## Erledigt

| Version | Inhalt |
|---|---|
| v1 | Artikel, Kisten, Kategorien, Fälligkeiten |
| v2 | Entnahmen, Inventur, Exporte, Etiketten |
| v3 | Benutzerverwaltung mit Rollen, JSON/CSV-Import und -Export |
| v4 | EAN, Wareneingang, Bewegungsprotokoll, Scan-Seite |
| v5 | Benutzerdefinierte Felder je Kategorie |
| v6 | Verpackungseinheiten mit Gebinde- und Einzelzählung |
| v7 | Inventur zählt Gebinde und lose Stücke getrennt |
| v8 | Einstellungen in der Datenbank, Einsatzknopf |
| v9 | Sollbestand je Artikel, Seite „Handlungsbedarf" |
| v10 | Lagerorte als eigene Hierarchie (Lager→Regal→Fach), Artikel direkt im Fach |
| v11 | Rückgaben als eigene Buchungen: Teilrückgaben bei Leihen, Rückläufer aus dem Einsatz, Rückgabemaske `rueckgabe.php` |

Zuletzt ohne eigene Migration: Scan-Seite versteht die eigenen QR-Codes
(`K:` und `A:`) und bucht Gebinde korrekt; Blanko-Scheine zum Ausdrucken;
Klebeetiketten mit QR in drei Rasterformaten; QR auch auf Regalschild und
Entnahmeschein, Scan-Seite versteht `R:` (Regal) und `E:` (Beleg);
Blanko-Leihschein; „Speichern & weiter“ beim Artikelanlegen;
Touch-Terminal (touch.php) fürs Lager-Tablet; Navigation in einer
Seitenleiste mit aufklappbarem Abschnitt „Verwaltung“, in der Kopfleiste
nur noch Terminal und Entnahme (ersetzt das geplante Umsortieren der
Kopfleiste).

Dazu quer über die Versionen: Regalschilder, Lagerübersicht zum Aushängen,
Entnahme- und Leihscheine, QR-Code auf dem Kistenetikett, Backup und Restore.

Repository umbenannt: es heißt jetzt `openstoragebox` (vorher
`lagerverwaltungdrk`), passend zum Produktnamen. GitHub hält eine
Weiterleitung vom alten Namen bereit; die Remote-URL der Klons
(Kubuntu-VM und VS-Code) wurde per `git remote set-url origin`
nachgezogen. Lokale Ordnernamen und der Docker-Mount blieben unberührt.

---

## 1. Gleiche Artikel mit unterschiedlichem MHD

**Das Problem:** Ein Artikel hat genau ein Fälligkeitsdatum. Wer zwei
Lieferungen Trinkwasser im Regal hat – eine mit MHD 01/2027, eine mit
06/2027 – kann das heute nicht sauber abbilden. Betrifft vor allem
Verpflegung, Sanitätsmaterial und Batterien, also genau das, was im
Einsatz gebraucht wird.

**Was heute geht:** Zwei Artikelzeilen mit gleichem Namen und gleicher EAN
anlegen. Das funktioniert, weil die EAN bewusst nicht eindeutig ist, und
der Lagerort trennt beide. Nachteile: Der Bestand steht an zwei Stellen,
die Gesamtzahl muss jemand im Kopf addieren, und beim Scannen erscheinen
beide Zeilen – man muss selbst wissen, welche die ältere ist.

**Zwei Wege:**

*a) Beim Workaround bleiben, ihn aber abfedern.* In der Artikelliste und
beim Scannen Artikel mit gleichem Namen zusammenfassen und die Summe
zeigen, das früheste MHD hervorheben. Kein Eingriff ins Datenmodell,
eine Abfrage mehr und etwas Anzeigelogik. Löst das Problem nicht, macht
es aber erträglich.

*b) Chargen als eigene Ebene.* Neue Tabelle `charge` mit MHD, Menge,
losen Stücken und Lagerort; der Artikel wird zur Stammdatenzeile ohne
eigenen Bestand. Fachlich richtig und die Grundlage für FIFO – bei der
Entnahme automatisch die älteste Charge zuerst, was bei Verpflegung
genau das ist, was man will.

Der Preis ist hoch: Bestand, Entnahme, Zugang, Scan, Inventur,
Gebindelogik und alle Exporte rechnen heute mit `artikel.menge`. Jede
dieser Stellen müsste auf Chargen umgestellt werden. Das ist kein
kleiner Punkt, sondern eine Version für sich – vergleichbar mit den
Verpackungseinheiten, eher größer.

**Bevor das entschieden wird,** wäre eine Zahl aus dem Betrieb nützlich:
Bei wie vielen Artikeln liegen tatsächlich mehrere MHD gleichzeitig im
Regal? Sind es drei, reicht Weg a. Sind es dreißig und jemand sortiert
regelmäßig aus, lohnt sich b. Diese Zahl lässt sich nach ein paar
Monaten Betrieb ablesen, vorher wäre sie geraten.

---

## 2. Einsatzauswertung

Seit v11 weiß jede Einsatzentnahme, was davon zurückkam und was
verbraucht ist. Was fehlt, ist die Sicht über einen ganzen Einsatz:
gruppiert nach `entnahme.bezug` – was wurde bei „Hochwasser Eisenach"
ausgegeben, was kam zurück, was ist verbraucht. Das Feld wird heute schon
gefüllt, nur nicht ausgewertet. Die Zahlen liefert `v_entnahme` bereits
(`menge_einzeln`, `zurueck_einzeln`, `offen_einzeln`); es braucht nur eine
Seite mit Gruppierung und Summen, gut als CSV für die Nachbeschaffung.

Haken: `bezug` ist Freitext. „Hochwasser Eisenach" und „HW Eisenach"
landen in verschiedenen Gruppen. Entweder beim Erfassen die bisherigen
Werte vorschlagen (datalist) oder gleich eine kleine Einsatztabelle.

---

## 3. JSON-Gesamtexport unvollständig

Beim Prüfen des Imports für v11 aufgefallen, unabhängig von den Rückgaben:

- **Zugänge, benutzerdefinierte Felder und Feldwerte fehlen im Export.**
  `EXPORT_TABELLEN` in `daten.php` kennt `zugang`, `feld` und
  `artikel_feldwert` nicht. Beim Umzug per JSON gehen Wareneingänge und
  Zusatzangaben verloren.
- **Exporte von vor v10 verlieren beim Import die Kistenorte.** Dort
  stehen Lager, Regal und Fach noch als Text an der Kiste; diese Spalten
  gibt es seit v10 nicht mehr, der Import überspringt sie stillschweigend.
  Die Kisten landen ohne Ort. Abhilfe wie bei den Rückgaben: für ältere
  Dateien die Texte beim Import in die Ort-Hierarchie überführen (die
  Logik aus `10_v10.sql` in PHP).

---

## 4. Kleinere offene Punkte

- **Drucken und Export trennen:** Statt einer Sammelseite zwei Einträge
  in der Seitenleiste. Was beides kann, taucht in beiden auf – die
  Artikelliste etwa als Druckansicht und als CSV.

  | Drucken | Export |
  |---|---|
  | Lagerübersicht (Aushang) | Artikelliste CSV |
  | Regalschilder | Kisteninhalt CSV |
  | Kistenetiketten A5 | Kistenverzeichnis CSV |
  | Klebeetiketten mit QR | Bewegungen CSV |
  | Blanko-Scheine | JSON-Gesamtexport |
  | Zählliste zur Inventur | JSON-Gesamtimport (Admin) |
  | Artikelliste als Druckansicht | CSV-Artikelimport (ab Bearbeiter) |

  Umbau: `export.php` in zwei Seiten teilen (`drucken.php`, `export.php`),
  `daten.php` geht im Exportmenü auf. Die eigentlichen Druck- und
  CSV-Erzeuger bleiben unverändert, es ändert sich nur die Verteilung der
  Einstiegspunkte. Mit der Seitenleiste ist das nur ein zusätzlicher
  Eintrag in `inc/header.php`.

- **Etikett und Aushang mit Gebindeangabe:** zeigen aktuell die Menge, nicht
  die Gesamtzahl. Auf einem Aushang ist „8 Kisten = 167 Flaschen“ die
  nützlichere Angabe.
- **CSV-Import um Gebindefelder erweitern:** `daten.php` kennt `ve_inhalt`
  und Co. noch nicht.
- **QR-Encoder Version 7 bis 10:** braucht die 18-Bit-Versionsinformation
  in zwei Blöcken. Erst nötig, wenn Codes länger als 108 Byte werden – bei
  Kennungen und kurzen URLs kein Thema.
- **Maske 6 im QR-Encoder:** ist ausgeschlossen, weil der Testscanner damit
  reproduzierbar scheiterte, obwohl Formel und Formatbits der Norm
  entsprechen. Ungeklärt, aber ohne praktische Folgen.
- **Wetterfeste Etiketten:** Das Raster passt auch auf Folienbögen, aber
  niemand hat das bisher gedruckt. Beim ersten Kauf einen Testbogen auf
  Normalpapier ziehen und gegen den Folienbogen halten.
- **HTTPS:** Ohne TLS gehen Passwörter im Klartext über die Leitung. Im
  Heimnetz hinter WireGuard vertretbar, für alles andere gehört Traefik mit
  Zertifikat davor.

---

## Bewusst nicht geplant

- **Mehrmandantenfähigkeit:** Die Anwendung verwaltet ein Lager eines
  Kreisverbands. Mehrere Organisationen nebeneinander würden das Datenmodell
  überall verkomplizieren, ohne dass es hier gebraucht wird.
- **Teilmengen mit Nachkommastellen:** Alle Mengen sind ganzzahlig. Für
  Meterware oder Flüssigkeiten wäre das falsch – dann wäre eine eigene
  Einheitenlogik nötig, kein einfaches Umstellen auf DECIMAL.
