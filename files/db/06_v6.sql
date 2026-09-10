-- =====================================================================
-- Lagerverwaltung – Migration v5 -> v6
--
-- Bringt: Verpackungseinheiten (Gebinde) mit getrennter Zählung von
--         vollen Gebinden und losen Einzelstücken.
--
--   docker compose exec -T db mariadb -u root -p"…" lager < db/06_v6.sql
--
-- Wiederholbar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Gebindeangaben am Artikel
--
-- Zählweise, die dem Lager entspricht:
--   menge  = Anzahl VOLLER Gebinde        (3 Kisten)
--   lose   = Einzelstücke außerhalb davon (7 Flaschen aus einer
--            angebrochenen Kiste)
--   gesamt = menge * ve_inhalt + lose     (3*20 + 7 = 67 Flaschen)
--
-- Warum nicht einfach alles in Einzelstücken zählen? Weil im Regal
-- Kisten stehen. Wer Bestand aufnimmt, zählt Kisten und schaut in die
-- angebrochene hinein – genau das bildet diese Aufteilung ab. Eine
-- reine Gesamtzahl müsste bei jeder Inventur zurückgerechnet werden.
--
-- ve_inhalt NULL bedeutet: kein Gebinde, menge ist die Stückzahl.
-- Bestehende Artikel bleiben damit unverändert gültig.
-- ---------------------------------------------------------------------
ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS ve_inhalt INT UNSIGNED NULL AFTER menge;

ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS ve_einheit VARCHAR(30) NULL AFTER ve_inhalt;

ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS gebinde_einheit VARCHAR(30) NULL AFTER ve_einheit;

ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS lose INT UNSIGNED NOT NULL DEFAULT 0 AFTER gebinde_einheit;

-- Ein Gebinde mit null Inhalt ergibt keinen Sinn; lose Stücke ohne
-- Gebinde ebenso wenig.
ALTER TABLE artikel
    ADD CONSTRAINT IF NOT EXISTS chk_ve CHECK (
        (ve_inhalt IS NULL AND lose = 0) OR (ve_inhalt >= 1)
    );

-- ---------------------------------------------------------------------
-- 2. Buchungen: in welcher Einheit wurde gebucht?
--
-- Ohne diese Angabe ließe sich eine Entnahme später nicht mehr lesen:
-- "3" könnte drei Kisten oder drei Flaschen heißen.
-- ---------------------------------------------------------------------
ALTER TABLE entnahme
    ADD COLUMN IF NOT EXISTS einheit ENUM('gebinde','einzeln') NOT NULL DEFAULT 'einzeln' AFTER menge;

ALTER TABLE zugang
    ADD COLUMN IF NOT EXISTS einheit ENUM('gebinde','einzeln') NOT NULL DEFAULT 'einzeln' AFTER menge;

-- ---------------------------------------------------------------------
-- 3. Views neu bauen
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_artikel AS
SELECT
    a.artikel_id,
    a.inventar_nr,
    a.ean,
    a.artikelname,
    a.menge,
    a.ve_inhalt,
    a.ve_einheit,
    a.gebinde_einheit,
    a.lose,
    -- Gesamtzahl in Einzelstücken; ohne Gebinde entspricht sie der Menge.
    (a.menge * IFNULL(a.ve_inhalt, 1) + a.lose) AS gesamt_einzeln,
    (a.ve_inhalt IS NOT NULL) AS hat_gebinde,
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

CREATE OR REPLACE VIEW v_bewegung AS
SELECT
    'abgang'                AS richtung,
    e.entnahme_id           AS bewegung_id,
    e.artikel_id,
    e.menge,
    e.einheit,
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
    z.einheit,
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
-- 4. Beispieldaten
-- ---------------------------------------------------------------------
UPDATE artikel
   SET ve_inhalt = 20, ve_einheit = 'Flaschen', gebinde_einheit = 'Kiste', menge = 8, lose = 7
 WHERE artikelname LIKE 'Trinkwasser%' AND ve_inhalt IS NULL;

INSERT INTO artikel (artikelname, kiste_id, standort_frei, kategorie_id, menge,
                     ve_inhalt, ve_einheit, gebinde_einheit, lose, notiz)
SELECT 'Mineralwasser still 0,5l', NULL, 'Halle B, Palette 1',
       (SELECT kategorie_id FROM kategorie WHERE bezeichnung = 'Lebensmittel' LIMIT 1),
       12, 24, 'Flaschen', 'Kiste', 5, 'Einsatzverpflegung'
 WHERE NOT EXISTS (SELECT 1 FROM artikel WHERE artikelname = 'Mineralwasser still 0,5l');

UPDATE artikel SET inventar_nr = CONCAT('A-', LPAD(artikel_id, 5, '0')) WHERE inventar_nr IS NULL;
