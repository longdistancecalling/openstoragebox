# Lagerverwaltung v1

Artikelverwaltung mit Lagerplatz, Kategorie und Ablauf-/Prüfdatum.
PHP 8.3 + MariaDB 11, als Docker-Compose-Stack.

---

## 1. Datenmodell

```
kategorie 1 ──── n artikel n ──── 1 kiste
                  (ON DELETE          (ON DELETE
                   SET NULL)           RESTRICT)
```

Die zentrale Entscheidung: **die Kiste ist der Lagerplatz**, nicht der Artikel.
`kiste_nr` hat einen UNIQUE-Index, damit ist deine Regel „jede Kiste kommt nur
einmal vor" eine Datenbankgarantie und keine Absprache.

Daraus folgt: Lager, Regal und Fach stehen genau einmal in der Datenbank.
Beim Umlagern änderst du eine Zeile in `kiste`, und alle darin liegenden Artikel
wandern automatisch mit. In deiner ursprünglichen flachen Struktur hättest du
jede betroffene Artikelzeile einzeln anfassen müssen — und beim kleinsten
Vertipper stünden zwei Artikel derselben Kiste an verschiedenen Orten.

Weitere Punkte:

| Entscheidung | Grund |
|---|---|
| `faellig_am` + `faellig_typ` statt zwei Datumsspalten | „Was ist in 30 Tagen fällig?" ist eine einzige WHERE-Klausel statt eines UNION |
| `CHECK chk_faellig` | verhindert ein Datum ohne Typ und einen Typ ohne Datum |
| `ON DELETE RESTRICT` bei der Kiste | eine Kiste mit Inhalt lässt sich nicht versehentlich löschen |
| `ON DELETE SET NULL` bei der Kategorie | eine gelöschte Kategorie darf keine Artikel mitreißen |
| View `v_artikel` | der Join Artikel↔Kiste↔Kategorie steht an einer Stelle, nicht in sechs Skripten |

---

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
docker-compose.yml        MariaDB + PHP/Apache + Adminer
docker/php/Dockerfile     php:8.3-apache mit pdo_mysql und mbstring
db/01_schema.sql          Schema, View und Beispieldaten
src/inc/config.php        PDO-Verbindung, Sortierkonstante
src/inc/helpers.php       Escaping, CSRF, Flash-Messages, Lagerplatz-Chip
src/inc/header.php        Kopf und Navigation
src/inc/footer.php        Fuß
src/assets/style.css      Stylesheet
src/index.php             Artikelliste mit Suche, Filter, Sortierung
src/artikel.php           Artikel anlegen, bearbeiten, löschen
src/kisten.php            Kistenübersicht mit Belegung
src/kiste.php             Kiste anlegen, bearbeiten, löschen
src/kategorien.php        Kategorien verwalten
src/faellig.php           Fälligkeiten nach Zeitraum und Art
```

---

## 6. Zwei Details, die leicht Ärger machen

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

## 7. Naheliegende Erweiterungen

- Benutzeranmeldung mit Rollen (lesen / bearbeiten)
- QR-Etiketten für Kisten, die direkt auf `index.php?q=K001` zeigen
- CSV-Export der Artikelliste
- Kistenverlauf: wann lag welche Kiste wo
- Mail-Erinnerung bei fälligen Prüfterminen (Cron-Container gegen `v_artikel`)
