-- =====================================================================
-- Lagerverwaltung – Migration v4 -> v5
--
-- Bringt: benutzerdefinierte Felder je Kategorie.
--
--   docker compose exec -T db mariadb -u root -p"$(grep DB_ROOT_PASS .env | cut -d= -f2-)" \
--     lager < db/05_v5.sql
--
-- Wiederholbar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Felddefinitionen
--
-- Warum an die Kategorie gebunden und nicht frei je Artikel?
-- Ein freies "Name: Wert" je Artikel wäre schneller gebaut, aber nach
-- einem halben Jahr stünde dort "Größe", "Groesse", "Grösse" und "Gr."
-- nebeneinander, und keine Auswertung würde mehr stimmen. Über die
-- Kategorie ist das Feld einmal definiert und überall gleich.
--
-- kategorie_id NULL bedeutet: gilt für alle Artikel, unabhängig von
-- der Kategorie (z. B. "Hersteller" oder "Seriennummer").
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS feld (
    feld_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    kategorie_id  INT UNSIGNED      NULL,
    bezeichnung   VARCHAR(60)   NOT NULL,
    typ           ENUM('text','zahl','datum','auswahl','ja_nein') NOT NULL DEFAULT 'text',
    einheit       VARCHAR(20)       NULL,   -- W, cm, kg, V …
    optionen      VARCHAR(500)      NULL,   -- bei typ='auswahl', durch | getrennt
    hinweis       VARCHAR(160)      NULL,
    pflicht       TINYINT(1)    NOT NULL DEFAULT 0,
    reihenfolge   SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (feld_id),
    KEY idx_feld_kategorie (kategorie_id, reihenfolge),
    CONSTRAINT fk_feld_kategorie
        FOREIGN KEY (kategorie_id) REFERENCES kategorie (kategorie_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Werte
--
-- Alle Werte als VARCHAR, auch Zahlen und Daten. Der Typ steuert nur
-- Eingabemaske und Prüfung. Getrennte Spalten je Typ wären sauberer
-- normalisiert, würden aber jede Abfrage verkomplizieren – bei dieser
-- Datenmenge kein sinnvoller Tausch.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artikel_feldwert (
    artikel_id  INT UNSIGNED  NOT NULL,
    feld_id     INT UNSIGNED  NOT NULL,
    wert        VARCHAR(255)  NOT NULL,
    PRIMARY KEY (artikel_id, feld_id),
    KEY idx_feldwert_feld (feld_id),
    CONSTRAINT fk_feldwert_artikel
        FOREIGN KEY (artikel_id) REFERENCES artikel (artikel_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_feldwert_feld
        FOREIGN KEY (feld_id) REFERENCES feld (feld_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. View erweitern
--
-- zusatzfelder liefert alle gesetzten Werte als fertigen Text. Damit
-- funktioniert die Volltextsuche über die Zusatzfelder ohne weiteren
-- Join, und Listen können sie direkt anzeigen.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_artikel AS
SELECT
    a.artikel_id,
    a.inventar_nr,
    a.ean,
    a.artikelname,
    a.menge,
    a.faellig_am,
    a.faellig_typ,
    a.notiz,
    a.kategorie_id,
    kat.bezeichnung AS kategorie,
    a.kiste_id,
    k.kiste_nr,
    k.lager,
    k.regal,
    k.fach,
    a.standort_frei,
    (a.kiste_id IS NULL) AS ohne_kiste,
    CASE
        WHEN a.kiste_id IS NOT NULL      THEN CONCAT_WS(' / ', k.lager, k.regal, k.fach)
        WHEN a.standort_frei IS NOT NULL THEN a.standort_frei
        ELSE NULL
    END AS lagerort_text,
    DATEDIFF(a.faellig_am, CURDATE()) AS tage_bis_faellig,
    (SELECT COALESCE(SUM(e.menge), 0)
       FROM entnahme e
      WHERE e.artikel_id = a.artikel_id
        AND e.grund = 'verliehen'
        AND e.zurueck_am IS NULL) AS verliehen_offen,
    (SELECT GROUP_CONCAT(
                CONCAT(f.bezeichnung, ': ', w.wert,
                       IF(f.einheit IS NULL OR f.einheit = '', '', CONCAT(' ', f.einheit)))
                ORDER BY f.reihenfolge, f.bezeichnung SEPARATOR ' · ')
       FROM artikel_feldwert w
       JOIN feld f ON f.feld_id = w.feld_id
      WHERE w.artikel_id = a.artikel_id
        AND w.wert <> '') AS zusatzfelder
FROM artikel a
LEFT JOIN kiste     k   ON k.kiste_id      = a.kiste_id
LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id;

-- ---------------------------------------------------------------------
-- 4. Beispielfelder
-- ---------------------------------------------------------------------
INSERT INTO feld (kategorie_id, bezeichnung, typ, einheit, optionen, hinweis, reihenfolge)
SELECT kategorie_id, 'Leistung', 'zahl', 'W', NULL, 'Nennleistung laut Typenschild', 10
  FROM kategorie WHERE bezeichnung = 'Elektro'
   AND NOT EXISTS (SELECT 1 FROM feld WHERE bezeichnung = 'Leistung');

INSERT INTO feld (kategorie_id, bezeichnung, typ, einheit, optionen, hinweis, reihenfolge)
SELECT kategorie_id, 'Spannung', 'auswahl', NULL, '230 V|400 V|12 V|24 V', NULL, 20
  FROM kategorie WHERE bezeichnung = 'Elektro'
   AND NOT EXISTS (SELECT 1 FROM feld WHERE bezeichnung = 'Spannung');

INSERT INTO feld (kategorie_id, bezeichnung, typ, einheit, optionen, hinweis, reihenfolge)
SELECT kategorie_id, 'Löschmittel', 'auswahl', NULL, 'ABC-Pulver|Schaum|CO2|Wasser', NULL, 10
  FROM kategorie WHERE bezeichnung = 'Sicherheitstechnik'
   AND NOT EXISTS (SELECT 1 FROM feld WHERE bezeichnung = 'Löschmittel');

-- Globales Feld: gilt für jeden Artikel, unabhängig von der Kategorie.
INSERT INTO feld (kategorie_id, bezeichnung, typ, einheit, optionen, hinweis, reihenfolge)
SELECT NULL, 'Hersteller', 'text', NULL, NULL, NULL, 90
 WHERE NOT EXISTS (SELECT 1 FROM feld WHERE bezeichnung = 'Hersteller' AND kategorie_id IS NULL);
