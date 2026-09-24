# OpenStorageBox – Handbuch für die Administration

DRK KV Eisenach · Stand Version 9

Dieses Heft richtet sich an die Person, die die Lagerverwaltung betreibt:
Installation, Sicherung, Benutzer, Updates. Für die tägliche Arbeit gibt es
ein eigenes Nutzerhandbuch.

Grundkenntnisse in Docker und der Kommandozeile werden vorausgesetzt. Die
Befehle sind für Linux und für Windows/PowerShell angegeben.

---

## 1. Was da läuft

Die Anwendung besteht aus drei Containern, die zusammen über
`docker compose` gestartet werden:

- **lager-web** – PHP 8.3 mit Apache, liefert die Oberfläche
- **lager-db** – MariaDB 11, hält alle Daten
- **lager-adminer** – optionale Datenbankoberfläche, nur zum Reinschauen

Der Code liegt in `src/` und ist per Bind-Mount eingebunden: Änderungen an
PHP-Dateien wirken sofort, ohne Neubau des Containers. Die Daten liegen in
einem Docker-Volume, getrennt vom Code.

**Projektstruktur:**

```
docker-compose.yml     der Stack
.env                   Zugangsdaten – NICHT im Repository
db/                    Schema und Migrationen (01_… bis 10_…)
docker/php/            das PHP-Image
src/                   die Anwendung
  inc/                 gemeinsame Bausteine (config, auth, helpers …)
  assets/              CSS
backup.sh              Sicherung
restore.sh             Wiederherstellung
```

---

## 2. Erstinstallation

### Voraussetzungen

Docker und Docker Compose. Sonst nichts – PHP, MariaDB und alle Bibliotheken
stecken in den Containern.

### Schritte

```bash
git clone <repo-adresse> drk-lager
cd drk-lager

# Zugangsdaten anlegen
cp .env.example .env
```

Dann in der `.env` die beiden Passwörter setzen. Unter Linux erzeugt man sie
so:

```bash
sed -i "s|CHANGEME_ROOT|$(openssl rand -base64 24)|" .env
sed -i "s|CHANGEME_APP|$(openssl rand -base64 24)|"  .env
```

Unter PowerShell:

```powershell
$root = [Convert]::ToBase64String((1..24 | ForEach-Object { Get-Random -Max 256 }))
$app  = [Convert]::ToBase64String((1..24 | ForEach-Object { Get-Random -Max 256 }))
(Get-Content .env) -replace 'CHANGEME_ROOT', $root -replace 'CHANGEME_APP', $app | Set-Content .env
```

Stack starten:

```bash
docker compose up -d --build
docker compose ps
```

Beide Container müssen laufen, `lager-db` auf `healthy`. Beim allerersten
Start mit leerem Volume spielt MariaDB automatisch **alle** SQL-Dateien aus
`db/` der Reihe nach ein – von `01_schema.sql` bis `10_v10.sql`. Man muss
nichts migrieren.

### Ersten Administrator anlegen

Die Anwendung im Browser öffnen. Da noch kein Benutzer existiert, erscheint
die **Ersteinrichtung**: hier den **Namen der Organisation** und den ersten
Administrator anlegen. Danach ist dieser Weg geschlossen. **Es gibt bewusst
kein Standardpasswort**, das jemand zu ändern vergessen könnte.

Der Produktname *OpenStorageBox* ist fest; die Organisation (DRK KV Eisenach
o. Ä.) benennt den Betreiber und erscheint auf allen Ausdrucken. Sie lässt
sich später unter *Einstellungen* ändern.

### Grundeinstellungen

Als Administrator unter *Verwaltung → Einstellungen*:

- **Organisation** – erscheint auf allen Ausdrucken.
- **Adresse der Anwendung** – ohne Schrägstrich am Ende, z. B.
  `http://192.168.1.50:8080`. **Vor dem ersten Etikettendruck setzen**:
  Ist sie hinterlegt, enthalten die QR-Codes eine vollständige URL, und ein
  Kamerascan öffnet direkt die Buchungsseite. Wird sie später geändert,
  müssen alle Etiketten neu gedruckt werden.
- **Vorwarnzeit** – ab wann Fälligkeiten als „bald" gelten.

---

## 3. Updates einspielen

Der Ablauf hängt davon ab, ob eine neue Datenbankversion dabei ist.

### Nur geänderte Dateien

```bash
git pull
docker compose restart web
```

Ein Neubau (`--build`) ist nur nötig, wenn sich das `Dockerfile` ändert.

### Mit Migration

Kommt eine neue Datei `db/NN_vN.sql` dazu, wird sie **einmalig von Hand**
eingespielt – die automatische Einspielung greift nur bei leerem Volume:

```bash
# Linux
docker compose exec -T db mariadb -u root -p"$(grep DB_ROOT_PASS .env | cut -d= -f2-)" \
  lager < db/NN_vN.sql
```

```powershell
# PowerShell
$pass = (Get-Content .env | Select-String "^DB_ROOT_PASS=").ToString().Split("=",2)[1]
Get-Content db\NN_vN.sql -Raw | docker compose exec -T db mariadb -u root -p"$pass" lager
```

Alle Migrationen sind **wiederholbar** – ein versehentlicher zweiter
Durchlauf schadet nicht.

> **Vorsicht bei `10_v10.sql`.** Diese Migration ist die einzige, die
> **bestehende Daten umschreibt**: Sie überführt die bisher an der Kiste
> klebenden Textfelder Lager/Regal/Fach in eine eigene Ort-Hierarchie und
> entfernt danach die alten Spalten. Vor dem Einspielen unbedingt ein
> Backup ziehen (`./backup.sh`). Sie ist so gebaut, dass ein zweiter
> Durchlauf nichts mehr anfasst – der Umbau läuft nur, solange die alten
> Spalten noch da sind. Nach der Migration werden Lagerorte unter
> *Verwaltung → Lagerorte* gepflegt.

### Kontrolle nach dem Update

```bash
docker compose logs --tail=20 web | grep -i php
```

Keine Ausgabe ist gut. Typische Fehler nach einem unvollständigen Update:

| Meldung | Ursache |
|---|---|
| `Unknown column '…'` | Migration fehlt – SQL-Datei einspielen |
| `Call to undefined function …` | eine Datei in `src/inc/` ist veraltet |
| `Undefined constant …` | dieselbe Ursache, meist `config.php` |
| `Table '….' doesn't exist` | Migration fehlt |

Die Faustregel: fehlt eine Funktion wie `bestand_rechnen` oder `qr_svg`, ist
`helpers.php` alt; fehlt `darf` oder `benutzer`, ist es `auth.php`.

---

## 4. Sicherung

**Der Code liegt im Repository, die Bestandsdaten nicht.** Ein `git clone`
stellt die Anwendung wieder her, aber keinen einzigen Artikel. Deshalb sind
regelmäßige Datenbanksicherungen unverzichtbar.

### Einrichten

```bash
chmod +x backup.sh restore.sh
./backup.sh            # einmal von Hand testen
```

Das Skript legt einen komprimierten Dump unter `~/backup/drk-lager` ab,
löscht Sicherungen älter als 30 Tage und kann sie mit `--offsite` per rclone
auf einen entfernten Speicher schieben.

Täglich per Crontab (`crontab -e`):

```
15 3 * * * /pfad/zu/drk-lager/backup.sh --offsite >> /var/log/drk-lager-backup.log 2>&1
```

### Wiederherstellen

```bash
./restore.sh                              # aus der Liste wählen
./restore.sh ~/backup/…/lager_….sql.gz    # bestimmte Datei
```

Das Skript zeigt den aktuellen Bestand, verlangt die Bestätigung `JA` und
sichert den **jetzigen** Stand vorher automatisch weg – ein Vertipper lässt
sich also rückgängig machen.

### Der wichtigste Satz zu Backups

**Ein Backup, das nie zurückgespielt wurde, ist kein Backup.** Einmal im
Quartal einen Restore auf einer Testkopie durchspielen. Die Skripte sind so
gebaut, dass sie das gefahrlos erlauben.

### Zweite Ebene

Die Dumps helfen nur, solange MariaDB läuft. Als menschenlesbaren Notnagel
zusätzlich gelegentlich den CSV-Export ziehen (*Export → Artikelliste*,
*Kisteninhalt*). Diese Dateien öffnet jede Tabellenkalkulation, auch ohne
Server.

---

## 5. Benutzer verwalten

Unter *Verwaltung → Benutzer*.

**Die drei Rollen** bauen aufeinander auf: Leser sieht und exportiert,
Bearbeiter bucht und pflegt, Administrator verwaltet. Die Prüfung sitzt
serverseitig – ein Bearbeiter kommt auch dann nicht an Admin-Funktionen,
wenn er die Adresse kennt.

**Benutzer werden nicht gelöscht, sondern deaktiviert.** So bleibt im
Protokoll nachvollziehbar, wer gebucht hat. Ein deaktiviertes Konto kann
sich nicht mehr anmelden.

**Passwort vergessen:** Ein Administrator öffnet den Benutzer und setzt ein
neues Passwort. Das hebt auch eine Sperre nach Fehlversuchen auf.

**Ein Administrator kann sich nicht selbst** deaktivieren oder herabstufen –
das verhindert, dass versehentlich der letzte Administrator verschwindet.

---

## 6. Stammdaten

**Kategorien** (*Verwaltung → Kategorien*) – ordnen Artikel und steuern,
welche Zusatzfelder erscheinen.

**Benutzerdefinierte Felder** (*Verwaltung → Felder*) – zusätzliche Angaben
je Kategorie, etwa Größe bei Kleidung oder Leistung bei Elektrogeräten. An
eine Kategorie gebunden erscheint ein Feld nur bei deren Artikeln. Fünf
Typen: Text, Zahl, Datum, Auswahl, Ja/Nein. Wird ein Feld gelöscht, gehen
die erfassten Werte mit – die Anwendung warnt vorher.

---

## 7. Datenaustausch

Unter *Verwaltung → Daten*.

**CSV-Artikelimport** (ab Bearbeiter) – Massenerfassung aus einer
Tabellenkalkulation. Erst Vorschau mit Prüfung jeder Zeile, dann Import.
Vorhandene Inventarnummern werden aktualisiert, unbekannte Kisten angelegt.
Ein einziger Fehler in der Datei verhindert den ganzen Import; die Vorschau
zeigt, wo.

**JSON-Gesamtexport** – alle Bestandsdaten in einer Datei, für Umzug auf
einen anderen Server oder als lesbare Sicherung. Benutzerkonten sind
absichtlich nicht enthalten.

**JSON-Gesamtimport** (nur Administrator) – ersetzt **alle** Bestandsdaten.
Alles-oder-nichts; zur Bestätigung muss `ERSETZEN` eingetippt werden. Vorher
unbedingt `./backup.sh` laufen lassen.

---

## 8. Absicherung im Netz

**Wichtig: Die Anwendung hat keine Verschlüsselung eingebaut.** Ohne
zusätzliche Maßnahme gehen Passwörter im Klartext über die Leitung.

Zum Datenschutz: In den Buchungen stehen Klarnamen von Helfern – das sind
personenbezogene Daten. Ungeschützt ins offene Internet gehört die
Anwendung nicht.

Je nachdem, wo der Server steht:

- **Nur im Vereinsnetz** – dann reicht das lokale Netz, solange es
  vertrauenswürdig ist.
- **Über ein VPN** (z. B. WireGuard) – den Port nur auf das VPN-Interface
  binden. Von außen ist die Anwendung dann gar nicht sichtbar. Das ist
  stärker als jedes Passwort.
- **Aus dem Internet erreichbar** – dann zwingend einen Reverse Proxy mit
  TLS davor (Traefik, Caddy o. Ä.). Vorbereitete Traefik-Labels stehen
  auskommentiert in der `docker-compose.yml`.

**Adminer** (Port 8081) ist eine ungeschützte Datenbankoberfläche und nur
zum Einrichten gedacht. Für den Dauerbetrieb aus der `docker-compose.yml`
entfernen oder auskommentieren.

---

## 9. Schema ändern und Fehlersuche

**Datenbank während der Entwicklung neu aufsetzen** (löscht alle Daten):

```bash
docker compose down -v
docker compose up -d --build
```

Das `-v` löscht das Volume, sodass die Init-Skripte wieder durchlaufen. Im
Betrieb niemals – dort mit Migrationsdateien arbeiten.

**Logs live verfolgen:**

```bash
docker compose logs -f web
```

Die Anwendung schreibt PHP-Fehler dorthin. Bei einem „Internal Server Error"
im Browser steht die Ursache hier im Klartext.

**Datenbank direkt ansehen:**

```bash
docker compose exec db mariadb -u root -p"$(grep DB_ROOT_PASS .env | cut -d= -f2-)" lager
```

**Container-Status:**

```bash
docker compose ps
docker compose restart web    # nur den Webserver neu starten
```

---

## 10. Zwei häufige Stolpersteine

**Frisch geklontes Repo, „Internal Server Error".** Fast immer fehlt die
`.env` (sie ist bewusst nicht im Repository) oder die Datenbank ist leer,
weil der erste Start ohne gültige `.env` lief. Lösung: `.env` anlegen (siehe
Abschnitt 2), dann `docker compose down -v && docker compose up -d --build`.
Nur auf einem System ohne echte Daten, weil `-v` alles löscht.

**Passwort mit Sonderzeichen bricht Befehle ab.** Base64-Passwörter enden
oft auf `=`. Beim Auslesen `cut -d= -f2-` verwenden (mit dem Minus), nicht
`-f2` – sonst wird nach dem ersten Gleichheitszeichen abgeschnitten.

---

## 11. Weiterentwicklung

Offene Punkte und ihre Begründung stehen in `ROADMAP.md`. Die beiden großen
sind Chargen mit unterschiedlichem Mindesthaltbarkeitsdatum und Rückläufer
aus dem Einsatz. Der Funktionsstand ist in `FEATURES.md` zusammengefasst.

Der Quelltext ist durchgehend auf Deutsch kommentiert, mit Betonung auf dem
*Warum* einer Entscheidung. Wer eine Stelle ändert, findet dort meist den
Grund, warum sie so ist.
