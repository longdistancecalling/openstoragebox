# Roadmap

Stand: 14.10.2026 · aktuelle Version v12

Offen sind die Einsatzauswertung, zwei Ausbaustufen der MHD-Chargen und
Kleinigkeiten. Keiner blockiert den Betrieb.

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
| v12 | MHD-Chargen: gleiche Artikel mit verschiedenen MHD, MHD beim Zugang, Chargenwahl bei der Entnahme, frühestes MHD als Fälligkeit |

Zuletzt ohne eigene Migration: Scan-Seite versteht die eigenen QR-Codes
(`K:` und `A:`) und bucht Gebinde korrekt; Blanko-Scheine zum Ausdrucken;
Klebeetiketten mit QR in drei Rasterformaten; QR auch auf Regalschild und
Entnahmeschein, Scan-Seite versteht `R:` (Regal) und `E:` (Beleg);
Blanko-Leihschein; „Speichern & weiter“ beim Artikelanlegen;
Touch-Terminal (touch.php) fürs Lager-Tablet; Navigation in einer
Seitenleiste mit aufklappbarem Abschnitt „Verwaltung“, in der Kopfleiste
nur noch Terminal und Entnahme (ersetzt das geplante Umsortieren der
Kopfleiste); JSON-Gesamtexport vollständig (jetzt auch Zugänge,
Zusatzfelder und Feldwerte), und der Import überführt Kistenorte aus
Dateien von vor v10 in die Ort-Hierarchie; Touch-Terminal neu aufgebaut
mit vier Bereichen (Entnahme, Einlagern, Übersicht, Bedarf), Auswahl per
Fingertipp und Rücksprung zur Startseite nach zwei Minuten; das Terminal
ist die Startseite, der rote Entnahme-Knopf in der Kopfleiste entfällt;
nach der Anmeldung geht es wieder zur ursprünglich aufgerufenen Seite;
Hell/Dunkel-Schalter in der Kopfleiste und Seite Verwaltung →
Darstellung mit den Designs Dunkel, Hoher Kontrast, Rot-Grün-sicher,
Nachtsicht, E-Ink, Rotes Plus, Rettungsdienst, Feuerwehr, Blaues Zahnrad, Wellenwacht,
Wald, Lagerbuch, Blaupause, Phosphor und Warnweste (in Gruppen, je mit
Live-Vorschau) sowie den Leseschriften
OpenDyslexic und Lexend, pro Gerät gespeichert.

Dazu quer über die Versionen: Regalschilder, Lagerübersicht zum Aushängen,
Entnahme- und Leihscheine, QR-Code auf dem Kistenetikett, Backup und Restore.

Repository umbenannt: es heißt jetzt `openstoragebox` (vorher
`lagerverwaltungdrk`), passend zum Produktnamen. GitHub hält eine
Weiterleitung vom alten Namen bereit; die Remote-URL der Klons
(Kubuntu-VM und VS-Code) wurde per `git remote set-url origin`
nachgezogen. Lokale Ordnernamen und der Docker-Mount blieben unberührt.

---

## 1. MHD-Chargen ausbauen

v12 hat die schlanke Variante umgesetzt: Chargen teilen den Bestand eines
Artikels nach MHD auf, alle liegen am Lagerort des Artikels, und
`artikel.menge` bleibt der Gesamtbestand. Zwei Ausbaustufen liegen nahe,
beide erst sinnvoll, wenn der Betrieb danach fragt:

- **Inventur je Charge.** Die Inventur zählt heute den Gesamtbestand;
  Abweichungen verteilt `chargen_angleichen()` (Fehlmenge von den
  ältesten, Mehrmenge zur jüngsten Charge). Das ist meist richtig, aber
  geraten. Genauer wäre eine Zählzeile je MHD bei Chargen-Artikeln – in
  `inventur_position` eine Spalte `mhd` und eine Position je Charge.
- **Chargen mit eigenem Lagerort.** Wenn eine neue Palette Wasser in
  Halle B steht und die alte im Keller, bräuchte die Charge einen eigenen
  Ort. Das berührt Scan, Kisteninhalt, Etiketten und Aushang – eine
  Version für sich.

Kleiner, aber nützlich: beim Entnehmen warnen, wenn bewusst nicht die
älteste Charge gewählt wird.

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

## 3. Kleinere offene Punkte

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
