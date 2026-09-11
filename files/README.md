# Lagerverwaltung v9

Artikelverwaltung mit Lagerplatz, Verpackungseinheiten, Barcode-Buchung,
Bewegungsprotokoll, Inventur, Benutzerverwaltung und Datenimport/-export.

Offene Punkte stehen in ROADMAP.md.
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

Für die weiteren Versionen ebenso `db/03_v3.sql` bis `db/09_v9.sql`, der Reihe nach. Beide Skripte sind wiederholbar – ein zweiter
Durchlauf richtet keinen Schaden an. Bei einer **Neuinstallation** werden alle
Dateien im Init-Verzeichnis alphabetisch eingespielt, du musst nichts migrieren.

**Nach der v3-Migration** ruft der erste Aufruf der Anwendung die Ersteinrichtung
auf: Solange kein Benutzer existiert, bietet die Login-Seite an, den ersten
Administrator anzulegen. Danach ist dieser Weg geschlossen. Es gibt kein
Standardpasswort.

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

## 4. Einstellungen

Unter *Einstellungen* (nur Administrator) stehen drei Werte, die vorher im
Quelltext standen:

| Einstellung | wirkt auf |
|---|---|
| Organisation | alle Ausdrucke und die Anmeldeseite |
| Adresse der Anwendung | Inhalt der QR-Codes |
| Vorwarnzeit für Fälligkeiten | ab wann ein Artikel als „bald fällig" gilt |

Die **Adresse** ist die wirkungsvollste: Ist sie gesetzt, enthalten die QR-Codes
eine vollständige URL statt nur der Kennung, und ein Scan mit der Handykamera
öffnet direkt die Buchungsseite. Ohne sie funktioniert nur der Weg über die
Scan-Seite.

Die **Vorwarnzeit** lag fest bei 30 Tagen. Für Prüftermine, zu denen ein
Fachbetrieb bestellt werden muss, sind 60 bis 90 Tage oft realistischer.

Die Tabelle ist ein allgemeines Schlüssel-Wert-Verzeichnis. Neue Einstellungen
brauchen deshalb keine Migration, nur einen Eintrag in der Liste am Anfang von
`einstellungen.php`.

---

## 5. Sicherheit

### Handlungsbedarf

Die Seite *Bedarf* fasst die drei Fragen zusammen, die jemand vor einer
Beschaffung stellt: Was ist überfällig, was fehlt, was steht demnächst an.
Einzeln verteilt müsste man drei Seiten abklappern und die Antworten selbst
zusammensetzen.

Der **Sollbestand** ist dabei das Gegenstück zu den Fälligkeiten: dort wird
Material zu alt, hier wird es zu wenig. Er ist optional – leer heißt keine
Überwachung, denn für Kabelbinder eine Mindestmenge zu erfinden hilft
niemandem.

Gezählt wird in **Einzelstücken**, auch bei Gebinden: „mindestens 240
Flaschen" gilt unabhängig davon, ob sie in 10er- oder 24er-Kisten kommen. Die
Bedarfsseite rechnet die Fehlmenge trotzdem in Gebinde um – „4 Kisten
nachbestellen" ist die Angabe, mit der man beim Händler anruft.

Die Unterdeckung ist nach dem **Anteil** sortiert, der fehlt, nicht nach der
absoluten Menge: Ein Artikel, von dem nur ein Fünftel da ist, drängt mehr als
einer, bei dem zehn von tausend Stück fehlen.

In der Artikelliste gibt es dazu den Filter *Sollbestand* und eine Markierung
an betroffenen Zeilen.

### Navigation

Vorn steht, was im Lageralltag gebraucht wird: Artikel, Scannen, Bewegungen,
Bedarf, Kisten, Inventur, Export. Alles, was man selten und meist im
Sitzen macht, liegt im Klappmenü **Verwaltung**: Kategorien, Daten, Felder,
Benutzer, Einstellungen.

Zwölf gleichrangige Punkte nebeneinander findet niemand mehr durch, und die
täglich genutzten gehen darin unter. Das Menü füllt sich nach Rolle – ein Leser
sieht dort nur *Daten* (für den Export), ein Bearbeiter zusätzlich
*Kategorien*, ein Administrator alles.

Öffnet man eine Verwaltungsseite, ist das Menü aufgeklappt und markiert, damit
erkennbar bleibt, wo man gerade steht. Umgesetzt ist es mit dem
`<details>`-Element: das funktioniert auch ohne JavaScript und ist mit der
Tastatur bedienbar.

### Der Einsatzknopf

Oben rechts steht auf jeder Seite ein roter **Entnahme**-Knopf, erreichbar auch
über Alt+E. Er sitzt bewusst außerhalb der Navigation und in einer Farbe, die
sonst nirgends vorkommt: Im Einsatz zählt, dass jemand unter Zeitdruck den Weg
zum Buchen findet, ohne die Leiste zu lesen. Leser sehen ihn nicht.

### Anmeldung und Rollen

Seit v3 verlangt jede Seite eine Anmeldung. Die Rollen bauen aufeinander auf:

| Rolle | darf |
|---|---|
| Leser | alles ansehen, alle Exporte (CSV, Druck, JSON) |
| Bearbeiter | zusätzlich anlegen, ändern, Entnahmen buchen, Rückgaben, Inventur starten und zählen, Kategorien anlegen, CSV-Import |
| Administrator | zusätzlich Artikel/Kisten/Kategorien/Inventuren löschen, Inventur abschließen, JSON-Gesamtimport, Benutzer verwalten |

Die Prüfung ist **serverseitig** in jedem POST-Handler (`erfordere('rolle')`).
Ausgeblendete Buttons sind Bequemlichkeit, keine Sicherheit – wer die URL kennt
und die Rolle nicht hat, bekommt 403.

Was die Anmeldung mitbringt:

- Passwörter mit `password_hash()` (bcrypt), mindestens 10 Zeichen
- nach 5 Fehlversuchen 15 Minuten Sperre – Passwortraten übers Netz lohnt sich nicht
- dieselbe Fehlermeldung, ob Benutzer unbekannt oder Passwort falsch: die Login-Seite
  verrät nicht, welche Konten es gibt; ein Dummy-`password_verify()` gleicht auch
  die Antwortzeit an
- `session_regenerate_id()` nach dem Login gegen Session-Fixation
- Logout nur per POST mit CSRF-Token – ein `<img src="logout.php">` auf einer
  fremden Seite kann niemanden abmelden
- ein Admin kann sich nicht selbst deaktivieren oder herabstufen
- Benutzer werden deaktiviert statt gelöscht, damit „gebucht von" im
  Entnahmeprotokoll erhalten bleibt

### Netzwerk

Die Anmeldung schützt die Anwendung, nicht den Transportweg. Ohne HTTPS gehen
Passwörter im Klartext über die Leitung. Für den Betrieb außerhalb des eigenen
Netzes gehört Traefik mit TLS davor, oder der Zugang läuft nur über WireGuard
(Port an die VPN-IP binden statt an `0.0.0.0`).

Adminer aus der Compose-Datei nehmen, sobald das Schema steht – das ist eine
Datenbankoberfläche ohne eigene Anmeldung.

Was schon drin ist: Prepared Statements ohne Emulation, konsequentes
Output-Escaping über `h()`, CSRF-Token bei allen schreibenden Formularen,
Whitelist für den Sortierparameter, Post/Redirect/Get gegen doppeltes Absenden.

---

## 6. Dateien

```
docker-compose.yml          MariaDB + PHP/Apache + Adminer
docker/php/Dockerfile       php:8.3-apache mit pdo_mysql
db/01_schema.sql            Grundschema (v1)
db/02_v2.sql                Migration auf v2, wiederholbar
src/inc/config.php          PDO-Verbindung, Sortierkonstanten, Entnahmegründe
src/inc/helpers.php         Escaping, CSRF, Flash, Lagerort-Chip, CSV-Ausgabe
src/inc/auth.php            Anmeldung, Rollen, Brute-Force-Sperre
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
src/login.php               Anmeldung und Ersteinrichtung
src/logout.php              Abmelden (nur per POST)
src/profil.php              eigenes Passwort ändern
src/benutzer.php            Benutzerverwaltung (Admin)
src/daten.php               JSON-Gesamtexport/-import, CSV-Artikelimport
src/zugang.php              Wareneingang buchen
src/gebinde.php             Gebindeübersicht
db/06_v6.sql                Migration auf v6 (Verpackungseinheiten)
db/07_v7.sql                Migration auf v7 (Inventur mit Gebinden)
ROADMAP.md                  offene Bausteine
src/scan.php                Buchen per Barcode
src/aushang.php             Lagerübersicht zum Aushängen
src/regal.php               Regalschilder
src/code.php                Klebeetiketten mit QR-Code
src/einstellungen.php       Organisation, Adresse, Vorwarnzeit
src/bedarf.php              Handlungsbedarf: überfällig, unter Soll, bald fällig
db/09_v9.sql                Migration auf v9 (Sollbestand)
db/08_v8.sql                Migration auf v8 (Einstellungen)
src/schein.php              Entnahme- und Leihschein
db/04_v4.sql                Migration auf v4 (EAN, Zugänge)
db/03_v3.sql                Migration auf v3 (Benutzer)
backup.sh / restore.sh      Datenbanksicherung und Wiederherstellung
```

---

## 7. Exporte

| Export | Format | Zweck |
|---|---|---|
| Artikelliste | Druck (A4) + CSV | alphabetisch, mit Nummer, Kiste und Lagerort |
| Entnahmeschein | Druck (A5 quer) | Beleg je Buchung, bei Leihe mit Unterschriftsfeldern |
| Blanko-Scheine | Druck (A5 quer) | leere Entnahme- und Einlagerungsscheine für den Vorrat |
| Klebeetiketten | Druck (A4) | QR-Etiketten für Artikel und Kisten, drei Rasterformate |
| Regalschilder | Druck (A5 quer) | ein Blatt je Regal, gegliedert nach Fach und Kiste |
| Lagerübersicht | Druck (A4) | kompletter Bestand zum Aushängen, vier Gliederungen |
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

## 8. Barcode und Bewegungen

### Scannen

Unter *Scannen* wird per EAN gebucht. Ein USB-Handscanner verhält sich wie eine
Tastatur – er tippt die Ziffern und schickt Enter hinterher. Deshalb genügt ein
Textfeld mit Autofokus; es braucht keine Scanner-Bibliothek und keine
Kamerarechte. Am Handy tippt man die Nummer ein oder nutzt die normale Suche.

Erkannt werden fünf Eingaben:

| Code | führt zu |
|---|---|
| `K:K001` | Kiste mit Inhaltsliste und Buchungsknöpfen |
| `A:A-00042` | Artikel mit Bestand und Buchung |
| `R:Keller\|R1` | alle Kisten eines Regals, zum Durchklicken |
| `E:E-00042` | die Buchung zu einem Papierbeleg, bei Leihe mit Rückgabe |
| `4006381333931` | Hersteller-EAN |

Damit lässt sich eine Kette durchklicken: Regalschild scannen, Kiste antippen,
Artikel buchen – ohne einmal zu tippen. Wird der QR-Code mit der
Handykamera gescannt, ruft diese die Seite direkt über `?c=…` auf – auch eine
komplett eingefügte URL wird verstanden.

Bei einer Kiste erscheint der gesamte Inhalt mit Buchungsknöpfen je Position –
man steht vor der Kiste, scannt einmal und bucht, was herausgeht.

Der Beleg-Code ist der Weg vom Papier zurück ins System: Wer einen
unterschriebenen Leihschein in der Hand hält, scannt ihn und bucht die Rückgabe,
ohne die Buchung suchen zu müssen.

Der Ablauf ist zweistufig: Scannen zeigt den Artikel mit Bestand, Lagerort und
Fälligkeit. Gebucht wird erst mit dem zweiten Klick – ein versehentlicher Scan
verändert nichts. Nach der Buchung bleibt die EAN stehen, damit sich derselbe
Artikel mehrfach hintereinander buchen lässt.

Gebucht wird immer auf den angemeldeten Benutzer und das heutige Datum. Für
Leihen verweist die Seite bewusst auf *Entnahme buchen* – dort wird festgehalten,
wer das Material hat, und das ist per Schnellscan nicht sinnvoll zu erfassen.

Eine unbekannte EAN ist kein Fehler, sondern der Normalfall beim ersten Scan:
die Seite bietet an, sie einem vorhandenen Artikel zuzuordnen oder einen neuen
anzulegen.

Die EAN ist **absichtlich nicht eindeutig**. Derselbe Artikel darf in mehreren
Kisten liegen und trägt dann überall dieselbe Nummer – beim Scannen erscheinen
dann beide Positionen mit ihrem jeweiligen Lagerort, und du buchst dort, wo du
gerade stehst. Die Prüfziffer wird nach dem EAN-Verfahren kontrolliert; stimmt
sie nicht, gibt es einen Hinweis, aber keine Sperre, weil hausinterne Codes
keine gültige Prüfziffer haben.

### Verpackungseinheiten

Ware, die in Gebinden lagert, wird so gezählt, wie sie im Regal steht:

```
menge  = Anzahl voller Gebinde        8 Kisten
lose   = Einzelstücke außerhalb       7 Flaschen aus einer angebrochenen
gesamt = menge × ve_inhalt + lose     8×20 + 7 = 167 Flaschen
```

Wer Bestand aufnimmt, zählt Kisten und schaut in die angebrochene hinein –
genau das bildet die Aufteilung ab. Eine reine Gesamtzahl müsste bei jeder
Inventur zurückgerechnet werden.

Beim Buchen wird die Einheit gewählt. Reichen bei einer Einzelentnahme die
losen Stücke nicht, **bricht das System automatisch ein Gebinde auf** – so wie
im Regal, wo jemand eine Kiste öffnet. Umgekehrt bildet ein Zugang einzelner
Stücke wieder volle Gebinde, sobald genug zusammenkommen:

```
8 Kisten + 7 lose   −2 Kisten     →  6 Kisten + 7 lose
6 Kisten + 7 lose   −10 Flaschen  →  5 Kisten + 17 lose   (aufgebrochen)
5 Kisten + 17 lose  +13 Flaschen  →  6 Kisten + 10 lose   (Kiste voll)
```

Die gebuchte Einheit steht mit im Protokoll – ohne sie wäre später nicht mehr
lesbar, ob „3" drei Kisten oder drei Flaschen meinte.

Unter *Gebinde* gibt es die Gesamtübersicht: volle Gebinde, Einzelstücke
gesamt, davon lose, gruppiert nach Gebindeart.

**In der Inventur** wird ebenfalls getrennt gezählt: eine Spalte für volle
Gebinde, eine für lose Einzelstücke. Ohne diese Trennung wäre bei acht Kisten
Wasser nicht klar, ob „8" acht Kisten oder acht Flaschen meint – zwei Zähler
trügen unterschiedliche Zahlen ein, und die Differenz wäre Glückssache.
Ausgewiesen wird die Differenz in **Einzelstücken**: eine fehlende Kiste à 24
erscheint als −24 Flaschen, nicht als −1. Nur so lassen sich Gebinde- und
Stückartikel in einer Zahl zusammenfassen.

Die Gebindeangaben werden beim Start der Inventur mitkopiert – wie Name und
Lagerort auch, damit ein abgeschlossener Beleg lesbar bleibt, wenn die
Verpackungseinheit später geändert wird.

Artikel ohne Verpackungseinheit bleiben unverändert – `ve_inhalt` leer heißt
Stückzählung wie bisher.

### Zugänge

Bis v3 konnte der Bestand nur sinken. Seit v4 gibt es den Wareneingang als
Gegenstück mit den Gründen Beschaffung, Spende, Rücklauf und Korrektur – in
einer eigenen Tabelle, weil eine Entnahme mit negativer Menge niemand lesen kann.

Gebucht wird über *Zugang buchen* (verlinkt aus der Artikelliste, dem
Entnahmeformular und der Bewegungsseite) oder per Scan. Anders als beim
Entnahmeformular sind hier auch Artikel mit Bestand 0 wählbar – genau die will
man einbuchen, wenn Nachschub kommt.

`Korrektur` ist für Zählfehler gedacht. Für regelmäßige Abgleiche ist die
Inventur der bessere Weg, weil sie Soll und Ist nebeneinander festhält statt
nur die Differenz zu buchen.

Der View `v_bewegung` fasst Zu- und Abgänge zusammen, deshalb zeigt *Bewegungen*
beides in einer Liste, mit Vorzeichen und Filter nach Richtung.

### Nur angemeldete Benutzer buchen

Das gilt seit v3 für alles: jede Seite verlangt eine Anmeldung, das Buchen
zusätzlich die Rolle Bearbeiter. Bei der Entnahme ist das Personenfeld mit dem
angemeldeten Benutzer vorbelegt (überschreibbar, weil man auch für jemand anderen
buchen kann), und unabhängig davon hält `erfasst_von` fest, wer die Buchung
tatsächlich angelegt hat.

### Ausdrucke fürs Lager

Drei Ebenen, die sich ergänzen:

| Ausdruck | Hängt wo | Beantwortet |
|---|---|---|
| Lagerübersicht | Tür, Wand am Eingang | Wo ist Artikel X? |
| Regalschild | Stirnseite des Regals | Was liegt in diesem Regal? |
| Kistenetikett | Auf oder in der Kiste | Was ist in dieser Kiste? |

Die **Lagerübersicht** (*Export → Lagerübersicht*) zeigt den kompletten Bestand
zweispaltig auf A4, in vier Gliederungen: nach Lagerort, nach Anfangsbuchstabe,
nach Kategorie oder als durchgehende A-bis-Z-Liste. Die letzte ist die
Suchliste – Name ablesen, Lagerort danebenstehen sehen.

Der **Entnahmeschein** (*Bewegungen → Schein*) ist der Beleg zu einer Buchung.
Bei einer Leihe ist er der eigentliche Zweck: er hält fest, wer wann was
mitgenommen hat, und wird unterschrieben. Deshalb trägt er dort zwei
Unterschriftsblöcke – Übergabe und Rückgabe – samt Hinweis auf Eigentum und
Haftung. Bei endgültigen Entnahmen genügt eine Empfangsbestätigung.

Nach dem Buchen einer Leihe springt die Anwendung direkt auf den Schein, damit
er unterschrieben wird, solange der Entleiher noch danebensteht. Über
*Leihscheine drucken* lassen sich alle offenen Leihen auf einmal ausgeben – etwa
für eine Nachfassaktion, wenn Material zu lange draußen ist.

Die **Klebeetiketten** (*Export → Klebeetiketten*) sind für alles gedacht, das
keine Hersteller-EAN hat: Getränkekisten, Aggregate, Zelte. Jedes Etikett trägt
einen QR-Code, Name, Lagerort und die Inventarnummer. Gescannt öffnet sich
direkt die Buchung.

| Format | Maße | je Bogen | wofür |
|---|---|---|---|
| Standard | 70 × 37 mm | 24 | Regelfall, randlos gerastert |
| Groß | 105 × 48 mm | 12 | Geräte und Kisten, Code aus einem Meter lesbar |
| Klein | 48,5 × 25,4 mm | 40 | Kleinteile, Text wird knapp |

Alle drei entsprechen verbreiteten Bögen (Avery Zweckform 3474, 3425, 3667 und
baugleiche), sind also nicht an einen Hersteller gebunden. Das Standardformat
ist randlos gerastert – drei Spalten à 70 mm ergeben exakt die Blattbreite –
und verzeiht dadurch kleine Druckversätze am ehesten.

Zwei Dinge, die beim Drucken zählen: **Skalierung auf 100 Prozent** stellen,
sonst verschiebt sich das Raster um Millimeter und keine Zeile trifft mehr. Und
für Material, das nass oder schmutzig wird, lohnen wetterfeste Folienetiketten
im selben Raster – Papier löst sich an einer Getränkekiste im Regen ab.

„Felder freilassen" überspringt schon abgezogene Etiketten, damit angebrochene
Bögen weiterverwendet werden können.

Das **Regalschild** (*Export → Regalschilder*) druckt ein A4-Blatt je Regal,
gegliedert nach Fächern und darin nach Kisten. Die Regalnummer steht in 46pt,
damit sie den Gang runter lesbar ist. Wahlweise alle Regale auf einmal oder ein
einzelnes nach dem Umräumen.

Zweispaltig, weil eine einspaltige Liste dieselbe Information auf doppelt so
viele Blätter verteilt – und niemand hängt fünf Seiten an die Tür. Überfällige
Prüftermine sind markiert. Über der Liste steht der Hinweis, dass der Ausdruck
mit jeder Buchung veraltet und Entnahmen trotzdem im System erfasst gehören.

---

## 9. Suchen und Filtern

**Entnahme buchen** – über der Artikelauswahl steht ein Suchfeld. Es durchsucht
Inventarnummer, Name, Kiste, Lagerort, Kategorie und Notiz und schränkt die
Auswahlliste ein. Bleibt genau ein Treffer, wird er automatisch ausgewählt –
nach der Suche ist das Formular sofort fertig. Ab 13 Artikeln in der Liste
erscheint zusätzlich ein Sofortfilter, der ohne Neuladen weiter eingrenzt; die
eigentliche Suche läuft serverseitig und funktioniert auch ohne JavaScript.

Der Suchbegriff steht in einem versteckten Feld des Buchungsformulars. Schlägt
eine Buchung fehl (etwa weil der Bestand nicht reicht), bleibt die Auswahl
erhalten statt zurück auf die volle Liste zu springen.

**Entnahmeliste** – neben Grund und Zeitraum lässt sich nach Kategorie filtern,
einschließlich „ohne Kategorie". Die Filter kombinieren sich, und die Kopfzeile
zeigt neben der Anzahl der Buchungen die Summe der entnommenen Stück – bei einer
Kategorieauswahl die eigentlich interessante Zahl. Ein Klick auf eine
Kategoriemarke in der Tabelle filtert direkt danach.

Der Knopf **CSV dieser Auswahl** übernimmt alle gesetzten Filter, sodass der
Export wirklich das enthält, was auf dem Bildschirm steht. Die Datei führt
zusätzlich Kategorie und den buchenden Benutzer.

---

## 10. Datenimport und -export

Unter *Daten* gibt es drei Funktionen:

**Gesamtexport (JSON)** – alle Bestandsdaten in einer Datei: Kategorien, Kisten,
Artikel, Entnahmen, Inventuren. Zum Umzug auf einen anderen Server oder als
Sicherung, die man auch ohne Datenbankzugriff lesen kann. Benutzerkonten sind
bewusst nicht enthalten – Passwort-Hashes gehören nicht in eine Datei, die per
Mail weitergeschickt wird.

**Gesamtimport (JSON, nur Admin)** – ersetzt alle Bestandsdaten durch die
Datei. Alles-oder-nichts in einer Transaktion; schlägt eine Zeile fehl, bleibt
die Datenbank unverändert. Zur Bestätigung muss `ERSETZEN` eingetippt werden.
Der Import schreibt nur Spalten, die es in der Zieltabelle gibt, und verkraftet
damit auch Dateien aus älteren Versionen. Benutzer bleiben erhalten.

**Artikelimport (CSV, ab Bearbeiter)** – Massenerfassung aus einer
Tabellenkalkulation. Erst Vorschau mit Prüfung jeder Zeile, dann Import.

| Spalte | Pflicht | Bemerkung |
|---|---|---|
| Artikel | ja | auch „Artikelname", „Name", „Bezeichnung" |
| Nummer | nein | vorhandene Inventarnummer → Artikel wird **aktualisiert** statt neu angelegt |
| Kiste | nein | unbekannte Kiste wird angelegt, wenn Lager, Regal und Fach mitgegeben sind |
| Lager, Regal, Fach | nein | nur für neue Kisten nötig |
| Standort | nein | freier Standort, wenn keine Kiste |
| Kategorie | nein | unbekannte Kategorie wird angelegt |
| Menge | nein | Standard 1 |
| Fällig am | nein | TT.MM.JJJJ oder JJJJ-MM-TT; ungültige Daten wie 31.02. werden abgewiesen |
| Art | nein | Ablauf oder Prüfung; fehlt sie bei gesetztem Datum, wird Ablauf angenommen |
| Notiz | nein | |

Groß-/Kleinschreibung und Reihenfolge der Spalten sind egal, Trennzeichen
Semikolon oder Komma, BOM und Windows-1252 werden erkannt. Die eigenen
CSV-Exporte lassen sich direkt wieder einlesen. Enthält die Datei auch nur einen
Fehler, wird nichts importiert – die Vorschau zeigt jede Zeile mit Problem.

---

## 11. Zwei Details, die leicht Ärger machen

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

Ein weiteres Detail aus v3: `ALTER TABLE … ADD CONSTRAINT IF NOT EXISTS` gilt
für CHECK-Constraints, bei Fremdschlüsseln steht `IF NOT EXISTS` dagegen
**hinter** `FOREIGN KEY`. Andersherum gibt es Syntaxfehler 1064.

Und eines aus PHP: Funktionsdefinitionen werden beim Laden einer Datei
vorgezogen, `const`-Deklarationen nicht. Eine Konstante, die unterhalb des Codes
steht, der sie benutzt, ist beim Aufruf noch nicht definiert. Deshalb stehen
alle Konstanten in `daten.php` ganz oben.

Ein drittes Detail ist beim Bau von v2 aufgetaucht: MariaDB lehnt einen
`CHECK` auf eine Spalte ab, die in einem Fremdschlüssel mit `ON UPDATE CASCADE`
steht – die Fehlermeldung lautet dann
`Function or expression 'kiste_id' cannot be used in the CHECK clause`.
Die Migration baut den Fremdschlüssel deshalb auf `ON UPDATE RESTRICT` um.
Da `kiste_id` auf einen AUTO_INCREMENT-Schlüssel zeigt, der sich nie ändert,
war CASCADE dort ohnehin wirkungslos.

---

## 12. Sicherung

Der Code liegt im Repository, die Bestandsdaten liegen im Docker-Volume.
Ein `git clone` stellt also die Anwendung wieder her, aber keinen einzigen
Artikel. Dafür gibt es zwei Skripte.

### Einrichten

```bash
chmod +x backup.sh restore.sh
./backup.sh                    # einmal von Hand testen
```

Täglich per Crontab (`crontab -e`):

```
15 3 * * * /home/marcel/docker/drk-lager/backup.sh --offsite >> /var/log/drk-lager-backup.log 2>&1
```

Das Skript legt einen komprimierten Dump unter `~/backup/drk-lager` ab,
löscht Sicherungen älter als 30 Tage und schiebt die Datei mit `--offsite`
per rclone auf die Storage Box. Das Remote lässt sich über die Variable
`RCLONE_REMOTE` anpassen.

### Warum die Optionen im Dump wichtig sind

| Option | Grund |
|---|---|
| `--single-transaction` | konsistenter Stand ohne Tabellensperre; ohne sie wäre das Lager während des Dumps blockiert, und eine gleichzeitige Buchung könnte halb in der Sicherung landen |
| `--routines --events` | nimmt den View `v_artikel` mit; fehlt er, startet die Anwendung nach dem Restore mit SQL-Fehlern |
| `--default-character-set=utf8mb4` | ohne das stehen Umlaute kaputt im Dump – und zwar unbemerkt bis zur Wiederherstellung |

Nach dem Dump prüft das Skript, ob die Tabelle `artikel` enthalten ist, und
schreibt zuerst nach `.teil`. Bricht der Dump ab, entsteht so keine Datei,
die fälschlich für eine gültige Sicherung gehalten wird.

### Wiederherstellen

```bash
./restore.sh                                   # aus der Liste wählen
./restore.sh ~/backup/drk-lager/lager_….sql.gz  # bestimmte Datei
```

Das Skript zeigt vorher den aktuellen Artikelbestand, verlangt eine
Bestätigung mit `JA` und legt den jetzigen Stand vorher als
`…_vor-restore_….sql.gz` ab. Die Datenbank wird verworfen und neu angelegt,
statt den Dump nur darüberzuspielen – sonst blieben Tabellen stehen, die es
zum Zeitpunkt der Sicherung noch nicht gab.

### Getestet

Datenbank vollständig gelöscht und aus dem Dump wiederhergestellt: 17 Artikel,
8 Kisten, 3 Entnahmen und 17 Inventurpositionen kamen zurück, der View
lieferte wieder Lagerorte und offene Leihen, Umlaute stimmten, und alle
Constraints griffen erneut (UNIQUE auf Kistennummer, `chk_lagerort`,
Fremdschlüssel).

**Ein Backup, das nie zurückgespielt wurde, ist kein Backup.** Plane einmal
im Quartal einen Restore auf einer Testdatenbank ein.

### Zweite Ebene: CSV

Die Dumps helfen nur, solange MariaDB läuft. Als menschenlesbaren Notnagel
lohnt sich zusätzlich der CSV-Export unter *Export → Artikelliste* und
*Kisteninhalt*. Diese Dateien kann jeder Tabellenkalkulation öffnen – auch
dann, wenn kein Server mehr steht.

---

## 13. Naheliegende Erweiterungen

- QR-Code auf dem Kistenetikett, der auf `index.php?q=K001` zeigt
- Barcode-Etiketten für Artikel ohne Hersteller-EAN drucken
- Kistenverlauf: wann lag welche Kiste wo
- Mail-Erinnerung bei fälligen Prüfterminen (Cron-Container gegen `v_artikel`)
- Einsatzbezogene Auswertung: was wurde bei welchem Einsatz verbraucht
  (`entnahme.bezug` ist dafür schon vorbereitet)
