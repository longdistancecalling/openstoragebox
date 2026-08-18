# Lagerverwaltung v2

Artikelverwaltung mit Lagerplatz, Entnahmeprotokoll, Inventur und Exporten.
PHP 8.3 + MariaDB 11, als Docker-Compose-Stack.

---

## 1. Datenmodell

```
kategorie 1 ─── n  artikel  n ─── 1 kiste        (Kiste optional)
                     │ 1
                     ├──── n entnahme
                     └──── n inventur_position n ─── 1 inventur
```

Die zentrale Entscheidung aus v1 gilt weiter: **die Kiste ist der Lagerplatz**,
nicht der Artikel. `kiste_nr` hat einen UNIQUE-Index, damit ist die Regel
"jede Kiste kommt nur einmal vor" eine Datenbankgarantie. Umlagern bleibt ein
einziges UPDATE, alle Artikel wandern mit.

| Entscheidung | Grund |
|---|---|
| `kiste_id` ist NULL-fähig, dazu `standort_frei` | Zelte, Feldbetten und Aggregate passen in keine Kiste |
| `CHECK chk_lagerort` | verhindert Kiste **und** freien Standort gleichzeitig – ein Artikel steht an genau einem Ort |
| `inventar_nr` getrennt von `artikel_id` | die Datenbank-ID ist ein technisches Detail, die Inventarnummer klebt am Material und darf eigenen Konventionen folgen |
| `entnahme.zurueck_am` nur bei `verliehen` | per CHECK erzwungen: die vier anderen Gründe sind endgültig |
| Snapshot-Spalten in `inventur_position` | eine abgeschlossene Inventur ist ein Beleg und muss lesbar bleiben, auch wenn Artikel später umbenannt werden |
| `faellig_am` + `faellig_typ` | "Was ist in 30 Tagen fällig?" ist eine WHERE-Klausel statt eines UNION |
| View `v_artikel` | Join und die Berechnung von `lagerort_text` und `verliehen_offen` stehen an einer Stelle |

### Bestandsführung

`artikel.menge` ist immer das, was **physisch im Regal liegt**. Jede Entnahme
verringert diesen Wert und schreibt gleichzeitig einen Protokolleintrag – beides
in einer Transaktion mit `SELECT … FOR UPDATE` auf der Artikelzeile. Ohne diese
Sperre könnten zwei gleichzeitige Entnahmen denselben Ausgangsbestand lesen und
den Bestand ins Minus schicken.

Bei `verliehen` bleibt die Buchung offen, bis du die Rückgabe bestätigst; dann
wandert die Menge zurück in den Bestand. Die Übersicht "Offene Leihen"
beantwortet damit die Frage, die im Lager am häufigsten gestellt wird: wo ist
das Zeug gerade.

### Entnahmegründe

| Grund | Kommt zurück | Typischer Fall |
|---|---|---|
| Einsatz | nein | verbraucht im Einsatz |
| Verschleiß | nein | abgenutzt, ausgesondert |
| Defekt | nein | kaputt, entsorgt |
| Abgelaufen | nein | Haltbarkeit überschritten |
| Verliehen | **ja** | an anderen Ortsverband oder Person |

## 2. Inbetriebnahme

### Schritt 1 – Zugangsdaten setzen

```bash
cd lagerverwaltung
cp .env.example .env
sed -i "s|CHANGEME_ROOT|$(openssl rand -base64 24)|" .env
sed -i "s|CHANGEME_APP|$(openssl rand -base64 24)|"  .env
```

### Schritt 2 – Stack starten

```bash
docker compose up -d --build
docker compose ps
```

**Prüfen:** alle drei Container laufen, `lager-db` steht auf `healthy`.
Das Schema wird beim ersten Start automatisch eingespielt.

### Schritt 2b – Migration auf v2 (nur bei bestehender v1-Installation)

Läuft der Stack schon mit v1-Daten, spielst du die Migration einmalig ein:

```bash
docker compose exec -T db mariadb -u root -p"$(grep DB_ROOT_PASS .env | cut -d= -f2)" \
  lager < db/02_v2.sql
```

Das Skript ist wiederholbar – ein zweiter Durchlauf richtet keinen Schaden an.
Bei einer **Neuinstallation** wird `db/02_v2.sql` automatisch nach `01_schema.sql`
eingespielt, weil das MariaDB-Image alle Dateien im Init-Verzeichnis alphabetisch
abarbeitet.

### Schritt 3 – Datenbank kontrollieren

```bash
docker compose exec db mariadb -u root -p"$(grep DB_ROOT_PASS .env | cut -d= -f2)" \
  lager -e "SHOW TABLES; SELECT COUNT(*) AS artikel FROM artikel;"
```

**Erwartet:** vier Objekte (`artikel`, `kategorie`, `kiste`, `v_artikel`) und
15 Artikel aus den Beispieldaten.

### Schritt 4 – Oberfläche öffnen

<http://SERVER-IP:8080>

Adminer liegt daneben auf Port 8081 (Server `db`, Benutzer aus der `.env`).

### Schritt 5 – Funktionstest

1. Artikel-Liste: Spaltenköpfe anklicken, Sortierung wechselt Richtung
2. Suche nach `Kabel` → fünf Treffer aus drei Kisten
3. Fälligkeiten → `Verlängerungskabel` und `Steckdosenleiste` mit DGUV-Termin
4. Kisten → `K001` bearbeiten, Lager auf `Garage` ändern → beide Patchkabel
   stehen sofort am neuen Platz
5. Kiste `K001` löschen wollen → wird mit Begründung abgelehnt
6. Entnahme buchen mit Grund *Einsatz* → Bestand sinkt, Protokolleintrag erscheint
7. Entnahme mit Grund *Verliehen* → steht unter „Offene Leihen"; „Zurück gebucht"
   klicken → Bestand steigt wieder
8. Export → Kistenetiketten A5 → Druckvorschau zeigt eine Seite je Kiste
9. Inventur starten → Zählwerte eintragen → Bericht zeigt Differenzen

---

## 3. Schema ändern

Das Init-Skript läuft **nur bei leerem Datenvolume**. Für einen sauberen
Neustart während der Entwicklung:

```bash
docker compose down -v      # -v löscht das Volume mitsamt aller Daten
docker compose up -d --build
```

Im Betrieb stattdessen mit einem Migrationsskript arbeiten:

```bash
docker compose exec -T db mariadb -u root -p'…' lager < db/02_migration.sql
```

PHP-Dateien sind per Bind-Mount eingebunden — Änderungen im Ordner `src/`
wirken sofort, ohne Rebuild.

---

## 4. Sicherheit

**Diese Version hat keine Benutzeranmeldung.** Wer die URL erreicht, darf alles.
Das ist für ein Heimnetz vertretbar, aber nicht fürs offene Internet. Drei Wege,
je nach Bedarf:

- **WireGuard**: den Port nur auf dem VPN-Interface binden, dann kommt niemand
  von außen dran — passt zu deinem bestehenden Hub-Setup
- **Traefik BasicAuth**: Middleware davor, ohne eine Zeile PHP zu ändern
  (die Traefik-Labels liegen auskommentiert in der `docker-compose.yml`)
- **Eigenes Login**: Tabelle `benutzer` mit `password_hash()`/`password_verify()`,
  Session-Prüfung in `inc/config.php`

Adminer solltest du für den Dauerbetrieb aus der Compose-Datei nehmen.

Was schon drin ist: Prepared Statements ohne Emulation, konsequentes
Output-Escaping über `h()`, CSRF-Token bei allen schreibenden Formularen,
Whitelist für den Sortierparameter, Post/Redirect/Get gegen doppeltes Absenden.

---

## 5. Dateien

```
docker-compose.yml          MariaDB + PHP/Apache + Adminer
docker/php/Dockerfile       php:8.3-apache mit pdo_mysql
db/01_schema.sql            Grundschema (v1)
db/02_v2.sql                Migration auf v2, wiederholbar
src/inc/config.php          PDO-Verbindung, Sortierkonstanten, Entnahmegründe
src/inc/helpers.php         Escaping, CSRF, Flash, Lagerort-Chip, CSV-Ausgabe
src/inc/header.php          Kopf und Navigation
src/inc/footer.php          Fuß
src/assets/style.css        Stylesheet Bildschirm
src/assets/druck.css        Stylesheet Druck (A5-Etiketten, A4-Listen)
src/index.php               Artikelliste mit Suche, Filter, Sortierung
src/artikel.php             Artikel anlegen, bearbeiten, löschen
src/entnahme.php            Entnahme buchen
src/entnahmen.php           Protokoll, offene Leihen, Rückgabe
src/kisten.php              Kistenübersicht mit Belegung
src/kiste.php               Kiste anlegen, bearbeiten, löschen
src/kategorien.php          Kategorien verwalten
src/faellig.php             Fälligkeiten nach Zeitraum und Art
src/export.php              Exportzentrale, CSV und Druckliste
src/etikett.php             A5-Kistenetiketten
src/inventur.php            Inventurübersicht, Start, Abschluss, Bericht
src/inventur_zaehlen.php    Erfassungsmaske und Zählliste zum Drucken
```

---

## 6. Exporte

| Export | Format | Zweck |
|---|---|---|
| Artikelliste | Druck (A4) + CSV | alphabetisch, mit Nummer, Kiste und Lagerort |
| Kistenetiketten | Druck (A5 quer) | eine Seite je Kiste mit vollständigem Inhalt |
| Kisteninhalt | CSV | eine Zeile je Artikel, nach Kiste gruppiert |
| Zählliste | Druck (A4) | nach Lagerort gruppiert, mit Leerfeldern zum Eintragen |
| Entnahmeprotokoll | CSV | alle Buchungen mit Grund, Person, Rückgabestatus |
| Kistenverzeichnis | CSV | alle Kisten mit Belegung |

Die Druckansichten erzeugen **kein PDF**, sondern nutzen CSS-Seitenformate
(`@page size: A5`) – der Browser druckt direkt oder speichert selbst als PDF.
Das spart eine PDF-Bibliothek im Container und sieht in der Praxis identisch aus.
Im Druckdialog musst du bei Etiketten **A5 und Querformat** auswählen.
Richtet sich der Drucker nicht nach `@page`, kommt sonst A4 hochkant heraus.

Auf eine A5-Seite quer passen etwa 12 bis 15 Positionen.
Passt der Inhalt einer Kiste nicht auf eine Seite, läuft er auf die nächste
Seite über. Die Kistennummer steht dabei in einem `<thead>`, das der Browser
per `display: table-header-group` auf jeder Folgeseite wiederholt – sonst wüsste
niemand, zu welcher Kiste Seite 2 gehört.

Die CSV-Dateien nutzen Semikolon als Trenner und beginnen mit einem UTF-8-BOM.
Ohne das BOM zeigt Excel unter Windows kaputte Umlaute, ohne Semikolon landet
alles in einer Spalte.

---

## 7. Zwei Details, die leicht Ärger machen

**Sortierung von Regalnummern.** Bei Textspalten sortiert `R10` vor `R2`, weil
Zeichen für Zeichen verglichen wird. Die Abfragen sortieren deshalb über
`LENGTH(regal), regal` — erst nach Stellenzahl, dann alphabetisch. Das gibt die
natürliche Reihenfolge, solange die Präfixe einheitlich sind. Wenn du später
`INT`-Spalten oder durchgängig `R01`/`R02` verwendest, kannst du das wieder
vereinfachen.

**Benannte Platzhalter kommen nur einmal vor.** Mit
`PDO::ATTR_EMULATE_PREPARES => false` bereitet MariaDB das Statement selbst vor,
und derselbe Platzhalter darf dort nicht mehrfach auftauchen — sonst kommt
`SQLSTATE[HY093] Invalid parameter number`. Die Volltextsuche fasst die Spalten
deshalb mit `CONCAT_WS()` zusammen und braucht nur ein `:q`.

---

Ein drittes Detail ist beim Bau von v2 aufgetaucht: MariaDB lehnt einen
`CHECK` auf eine Spalte ab, die in einem Fremdschlüssel mit `ON UPDATE CASCADE`
steht – die Fehlermeldung lautet dann
`Function or expression 'kiste_id' cannot be used in the CHECK clause`.
Die Migration baut den Fremdschlüssel deshalb auf `ON UPDATE RESTRICT` um.
Da `kiste_id` auf einen AUTO_INCREMENT-Schlüssel zeigt, der sich nie ändert,
war CASCADE dort ohnehin wirkungslos.

---

## 8. Naheliegende Erweiterungen

- Benutzeranmeldung mit Rollen (lesen / buchen / verwalten)
- QR-Code auf dem Kistenetikett, der auf `index.php?q=K001` zeigt
- Wareneingang als Gegenstück zur Entnahme (aktuell wird der Bestand direkt
  im Artikel korrigiert)
- Kistenverlauf: wann lag welche Kiste wo
- Mail-Erinnerung bei fälligen Prüfterminen (Cron-Container gegen `v_artikel`)
- Einsatzbezogene Auswertung: was wurde bei welchem Einsatz verbraucht
  (`entnahme.bezug` ist dafür schon vorbereitet)
