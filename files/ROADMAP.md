# Roadmap

Stand: 10.09.2026 · aktuelle Version v7

Offene Bausteine in der Reihenfolge, in der sie sinnvoll umzusetzen sind.
Jeder Punkt nennt, was zu tun ist und warum er an dieser Stelle steht.

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

Dazu quer über die Versionen: Regalschilder, Lagerübersicht zum Aushängen,
Entnahme- und Leihscheine, QR-Code auf dem Kistenetikett, Backup und Restore.

---

## 1. Scan-Seite um die eigenen Codes erweitern

**Warum zuerst:** Der QR-Code auf dem Kistenetikett existiert bereits und
enthält `K:K001`. Die Scan-Seite versteht diesen Code aber noch nicht – sie
sucht nur nach EAN. Der Code ist damit gedruckt, aber ohne Funktion. Das ist
die kleinste Lücke mit der größten Wirkung.

**Zu tun:**
- `scan.php`: Eingabe zuerst auf das Präfix prüfen
  - `K:` → Kiste laden, Inhalt zeigen, Buchung je Artikel anbieten
  - `A:` → Artikel laden wie bisher bei der EAN
  - sonst → EAN-Suche wie bisher
- Kommt der Code aus einer URL (`?c=K:K001`), muss `scan.php` den Parameter
  ebenso auswerten – die Handykamera ruft die Seite direkt auf.
- Kistenansicht: nicht nur ein Artikel, sondern die Liste des Kisteninhalts
  mit je einem Entnehmen- und Einbuchen-Knopf.

**Aufwand:** überschaubar, eine Datei.

---

## 2. Eigene Codes für Artikel

**Warum danach:** Erst wenn die Scan-Seite `A:`-Codes versteht, ergibt ein
Artikeletikett Sinn.

**Zu tun:**
- Neue Seite `code.php`: druckbare Bögen mit Klebeetiketten, je Etikett
  QR-Code, Inventarnummer und Artikelname.
- Auswahl: einzelner Artikel, eine Kategorie, alle ohne Code, oder eine
  freie Auswahl.
- Layout an gängige Etikettenbögen anlehnen – ein Raster aus gleich großen
  Feldern auf A4, damit selbstklebende Bögen bedruckt werden können.
- Auf dem Regalschild und dem Kistenetikett steht der QR bereits; für
  einzelne Gegenstände (Aggregat, Zelt) braucht es das Artikeletikett.

**Offene Frage:** Welches Etikettenformat verwendet der Kreisverband? Das
Raster sollte dazu passen, sonst wird geklebt statt bedruckt.

---

## 3. Blanko-Scheine

**Warum hier:** Unabhängig von allem anderen, klein, jederzeit machbar.

**Zu tun:**
- `schein.php?blanko=entnahme` und `?blanko=zugang`
- Gleiches Layout wie der gefüllte Schein, aber mit Linien statt Werten:
  Artikel, Menge, Grund, Person, Datum, Unterschrift.
- Mehrere je Seite oder einzeln – vermutlich ein Vorrat, den man ausdruckt
  und ins Lager legt, damit auch ohne Rechner etwas festgehalten wird.
- Sinnvolle Ergänzung: eine kurze Zeile „bitte nachtragen unter …“ mit der
  Adresse aus `BASIS_URL`.

---

## 4. QR-Code auch auf Regalschild und Schein

**Zu tun:**
- Regalschild: QR mit `R:Lager|Regal`, führt auf eine Regalansicht.
  Dafür bräuchte es eine Seite, die ein Regal im Browser zeigt – die gibt
  es bisher nur als Druckansicht.
- Entnahmeschein: QR mit der Belegnummer, damit sich ein Papierbeleg
  wiederfinden lässt.

---

## 5. Kleinere offene Punkte

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
