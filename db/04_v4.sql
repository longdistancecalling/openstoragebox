-- =====================================================================
-- Lagerverwaltung – Migration v3 -> v4
--
-- Bringt: EAN je Artikel, Wareneingang als Gegenstück zur Entnahme,
--         gemeinsamer View über alle Bestandsbewegungen.
--
--   docker compose exec -T db mariadb -u root -p"$(grep DB_ROOT_PASS .env | cut -d= -f2-)" \
--     lager < db/04_v4.sql
--
-- Wiederholbar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. EAN
--
-- Bewusst NICHT eindeutig: derselbe Artikel kann in mehreren Kisten
-- liegen, und dann tragen beide Positionen dieselbe EAN. Ein Index
-- sorgt trotzdem dafür, dass das Nachschlagen beim Scannen schnell ist.
--
-- VARCHAR statt Zahl: führende Nullen gehören zur EAN und gingen in
-- einer numerischen Spalte verloren.
-- ---------------------------------------------------------------------
ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS ean VARCHAR(14) NULL AFTER inventar_nr;

ALTER TABLE artikel
    ADD INDEX IF NOT EXISTS idx_artikel_ean (ean);

-- ---------------------------------------------------------------------
-- 2. Zugang (Wareneingang)
--
-- Spiegelbild der Entnahme. Eigene Tabelle statt eines
-- Vorzeichens in `entnahme`, weil die Gründe andere sind und eine
-- Entnahme mit negativer Menge niemand lesen kann.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zugang (
    zugang_id     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    artikel_id    INT UNSIGNED  NOT NULL,
    menge         INT UNSIGNED  NOT NULL,
    grund         ENUM('beschaffung','spende','ruecklauf','korrektur') NOT NULL DEFAULT 'beschaffung',
    datum         DATE          NOT NULL,
    person        VARCHAR(80)       NULL,
    bezug         VARCHAR(120)      NULL,   -- Lieferant, Spender, Rechnung
    notiz         VARCHAR(255)      NULL,
    erfasst_von   INT UNSIGNED      NULL,
    erfasst_am    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (zugang_id),
    KEY idx_zugang_artikel (artikel_id),
    KEY idx_zugang_datum (datum),
    CONSTRAINT fk_zugang_artikel
        FOREIGN KEY (artikel_id) REFERENCES artikel (artikel_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_zugang_benutzer
        FOREIGN KEY (erfasst_von) REFERENCES benutzer (benutzer_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_zugang_menge CHECK (menge > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. View über alle Bewegungen
--
-- Fasst Zugänge und Abgänge zu einer Liste zusammen, damit das
-- Protokoll beides in einer Abfrage zeigen kann. Die
-- Bewegungsnummer ist je Tabelle eindeutig, deshalb wird die
-- Richtung als Präfix mitgeführt.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_bewegung AS
SELECT
    'abgang'                AS richtung,
    e.entnahme_id           AS bewegung_id,
    e.artikel_id,
    e.menge,
    CAST(e.grund AS CHAR)   AS grund,
    e.entnommen_am          AS datum,
    e.person,
    e.bezug,
    e.notiz,
    e.zurueck_am,
    e.erfasst_von,
    e.erfasst_am
FROM entnahme e
UNION ALL
SELECT
    'zugang',
    z.zugang_id,
    z.artikel_id,
    z.menge,
    CAST(z.grund AS CHAR),
    z.datum,
    z.person,
    z.bezug,
    z.notiz,
    NULL,
    z.erfasst_von,
    z.erfasst_am
FROM zugang z;

-- ---------------------------------------------------------------------
-- 4. Artikelview um die EAN erweitern
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
        AND e.zurueck_am IS NULL) AS verliehen_offen
FROM artikel a
LEFT JOIN kiste     k   ON k.kiste_id      = a.kiste_id
LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id;

-- ---------------------------------------------------------------------
-- 5. Beispiel-EANs für die Testdaten
-- ---------------------------------------------------------------------
UPDATE artikel SET ean = '4006381333931' WHERE artikelname LIKE 'Patchkabel Cat6 2m'   AND ean IS NULL;
UPDATE artikel SET ean = '4260363770019' WHERE artikelname LIKE 'Feuerlöscher%'        AND ean IS NULL;
UPDATE artikel SET ean = '4009900484169' WHERE artikelname LIKE 'Erste-Hilfe-Kasten%'  AND ean IS NULL;
UPDATE artikel SET ean = '4002395408054' WHERE artikelname LIKE 'Kabelbinder%'         AND ean IS NULL;
