# Roadmap

Stand: 10.09.2026 · aktuelle Version v7

Zwei größere offene Punkte (Chargen mit MHD, Rückläufer aus dem Einsatz),
ansonsten Kleinigkeiten. Keiner blockiert den Betrieb, aber Punkt 2 führt
heute zu falschen Verbrauchszahlen.

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

Zuletzt ohne eigene Migration: Scan-Seite versteht die eigenen QR-Codes
(`K:` und `A:`) und bucht Gebinde korrekt; Blanko-Scheine zum Ausdrucken;
Klebeetiketten mit QR in drei Rasterformaten; QR auch auf Regalschild und
Entnahmeschein, Scan-Seite versteht `R:` (Regal) und `E:` (Beleg);
Blanko-Leihschein; „Speichern & weiter“ beim Artikelanlegen.

Dazu quer über die Versionen: Regalschilder, Lagerübersicht zum Aushängen,
Entnahme- und Leihscheine, QR-Code auf dem Kistenetikett, Backup und Restore.

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

## 2. Rückläufer aus dem Einsatz

**Das Problem:** „Einsatz" ist als endgültige Entnahme modelliert. In der
Praxis kommt aber Material zurück, das nicht gebraucht wurde – volle
Kisten, unangebrochene Verpflegung. Die Aussage „endgültig – eine
Rückgabe ist nicht vorgesehen" auf dem Entnahmeschein ist damit
schlicht falsch.

**Was heute geht:** Ein Zugang mit dem Grund „Rücklauf". Der Bestand
stimmt danach wieder, aber die Verbindung zur ursprünglichen Entnahme
fehlt. Niemand kann nachher sagen, dass von 20 ausgegebenen Kisten 7
zurückkamen und 13 tatsächlich verbraucht wurden – für die Nachbereitung
eines Einsatzes und die Nachbeschaffung ist genau das die interessante
Zahl.

**Die zweite Lücke, die dabei auffällt:** Auch bei Leihen ist die
Rückgabe heute alles oder nichts. `entnahme.zurueck_am` ist ein einzelnes
Datum – werden 10 Stück verliehen und kommen 7 zurück, lässt sich das
nicht erfassen. Beide Fälle brauchen dieselbe Lösung.

**Lösungsweg:** Eigene Tabelle `rueckgabe` mit `entnahme_id`, Menge,
Einheit, Datum und Benutzer. Damit sind Teilrückgaben und mehrere
Rückgaben zu einer Entnahme möglich; `zurueck_am` wird durch
`SUM(rueckgabe.menge)` gegenüber `entnahme.menge` abgelöst. Der Grund
„Einsatz" verliert seine Sonderstellung: rückgabefähig wären dann
Einsatz **und** Verleih, endgültig blieben Verschleiß, Defekt und
Abgelaufen.

Zu ändern wären: Migration, `entnahmen.php` (Rückgabemaske statt
Ein-Klick-Knopf), `schein.php` (Text und Unterschriftsblock auch beim
Einsatzschein), `scan.php` und die Fälligkeits-/Bewegungsansichten.
Überschaubarer als die Chargen oben, aber kein Nachmittag.

**Sinnvolle Ergänzung dabei:** eine Einsatzauswertung über
`entnahme.bezug` – was wurde bei „Hochwasser Eisenach" ausgegeben, was
kam zurück, was ist verbraucht. Das Feld wird heute schon gefüllt, nur
nicht ausgewertet.

---

## 3. Kleinere offene Punkte

- **Lager und Regale eigenständig anlegen:** Heute entstehen Lager, Regal
  und Fach nur als Nebenprodukt beim Anlegen einer Kiste – es gibt keine
  Stelle, an der man die Struktur des Lagers vorab aufbaut. Zwei Folgen:
  Ein Regal ohne Kisten existiert nicht (taucht auf keinem Regalschild auf),
  und ein Tippfehler im Lagernamen erzeugt stillschweigend ein zweites
  „Kelller".

  Lösungsweg: eine schlanke Verwaltung für Lagerorte – Lager und Regale als
  eigene Liste, aus der die Kiste dann auswählt statt frei zu tippen. Das
  verhindert Dubletten und erlaubt leere Regale. Berührt das Datenmodell
  (bisher stehen `lager`/`regal`/`fach` als Text in der Kiste), daher kein
  ganz kleiner Punkt – eher eine eigene kleine Version. Die freien
  Standorte (`standort_frei`) blieben davon unberührt.

- **Kopfleiste umsortieren:** Entnahmeknopf nach links, Verwaltungsmenü nach
  rechts. Aktuell steht der Knopf ganz rechts und das Menü am Ende der
  Navigation. Reine CSS-Änderung an `.einsatz` und `.menue` in `style.css`,
  dazu die Reihenfolge im Markup von `inc/header.php`.

- **Drucken und Export trennen:** Statt einer Sammelseite zwei
  Navigationsmenüs. Was beides kann, taucht in beiden auf – die
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
  Einstiegspunkte. Zusammen mit der Kopfleisten-Umsortierung oben in einem
  Durchgang sinnvoll, weil beides `inc/header.php` anfasst.

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
