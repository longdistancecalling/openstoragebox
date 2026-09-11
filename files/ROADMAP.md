# Roadmap

Stand: 10.09.2026 · aktuelle Version v7

Die großen Bausteine sind abgearbeitet. Was bleibt, sind kleinere Punkte –
keiner davon blockiert den Betrieb.

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

Zuletzt ohne eigene Migration: Scan-Seite versteht die eigenen QR-Codes
(`K:` und `A:`) und bucht Gebinde korrekt; Blanko-Scheine zum Ausdrucken;
Klebeetiketten mit QR in drei Rasterformaten; QR auch auf Regalschild und
Entnahmeschein, Scan-Seite versteht `R:` (Regal) und `E:` (Beleg).

Dazu quer über die Versionen: Regalschilder, Lagerübersicht zum Aushängen,
Entnahme- und Leihscheine, QR-Code auf dem Kistenetikett, Backup und Restore.

---

## 1. Kleinere offene Punkte

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
