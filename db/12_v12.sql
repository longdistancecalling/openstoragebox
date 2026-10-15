-- =====================================================================
-- Lagerverwaltung – Migration v11 -> v12
--
-- Bringt: MHD-Chargen. Gleiche Lebensmittel aus verschiedenen
-- Lieferungen behalten ihr eigenes Mindesthaltbarkeitsdatum.
--
-- Bisher hatte ein Artikel genau ein Datum (faellig_am). Zwei
-- Lieferungen Trinkwasser mit MHD 01/2027 und 06/2027 ließen sich nur
-- als zwei Artikelzeilen abbilden. Jetzt kann ein Artikel als "mit
-- Chargen" markiert werden: sein Bestand ist dann nach MHD aufgeteilt.
--
-- Bewusst schlank gehalten:
--   * artikel.menge/lose bleiben der Gesamtbestand. Die Chargen teilen
--     ihn nur auf (in Einzelstücken), alles, was mit dem Gesamtbestand
--     rechnet – Liste, Exporte, Gebinde, Sollbestand – bleibt unverändert.
--   * Alle Chargen liegen am Lagerort des Artikels.
--   * faellig_am eines Chargen-Artikels pflegt die Anwendung selbst:
--     es ist immer das früheste MHD, von dem noch etwas da ist. Damit
--     warnen Bedarf und Fälligkeiten ohne weitere Änderung.
--
--   docker compose exec -T db mariadb -u root -p"…" lager < db/12_v12.sql
--
-- Wiederholbar. Ändert keine bestehenden Daten.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Markierung am Artikel
-- ---------------------------------------------------------------------
ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS mit_chargen TINYINT(1) NOT NULL DEFAULT 0 AFTER faellig_typ;

-- ---------------------------------------------------------------------
-- 2. Charge
--
-- Eine Zeile je Artikel und MHD; gleiches MHD wird zusammengezählt
-- (UNIQUE). Leere Chargen löscht die Anwendung, deshalb menge > 0.
-- Menge in Einzelstücken, damit auch angebrochene Gebinde einer Charge
-- zugeordnet bleiben.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS charge (
    charge_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    artikel_id   INT UNSIGNED  NOT NULL,
    mhd          DATE          NOT NULL,
    menge        INT UNSIGNED  NOT NULL,
    angelegt_am  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (charge_id),
    UNIQUE KEY uq_charge (artikel_id, mhd),
    KEY idx_charge_mhd (mhd),
    CONSTRAINT fk_charge_artikel
        FOREIGN KEY (artikel_id) REFERENCES artikel (artikel_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_charge_menge CHECK (menge > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Welche Charge betraf eine Buchung?
--
-- Das MHD statt der charge_id: Chargen verschwinden, wenn sie leer
-- sind. Kommt später etwas zurück, lässt sich die Charge über das MHD
-- wieder anlegen.
-- ---------------------------------------------------------------------
ALTER TABLE entnahme
    ADD COLUMN IF NOT EXISTS mhd DATE NULL AFTER einheit;

ALTER TABLE zugang
    ADD COLUMN IF NOT EXISTS mhd DATE NULL AFTER einheit;

-- ---------------------------------------------------------------------
-- 4. Views
--
-- Gegenüber v11 nur ergänzt: mhd in v_entnahme und v_bewegung,
-- mit_chargen, anzahl_chargen und chargen_text in v_artikel.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_entnahme AS
SELECT
    e.entnahme_id,
    e.artikel_id,
    e.menge,
    e.einheit,
    e.mhd,
    e.grund,
    e.entnommen_am,
    e.person,
    e.bezug,
    e.notiz,
    e.erfasst_von,
    e.erfasst_am,
    e.menge * IF(e.einheit = 'gebinde', IFNULL(a.ve_inhalt, 1), 1) AS menge_einzeln,
    (SELECT COALESCE(SUM(r.menge * IF(r.einheit = 'gebinde', IFNULL(a.ve_inhalt, 1), 1)), 0)
       FROM rueckgabe r
      WHERE r.entnahme_id = e.entnahme_id) AS zurueck_einzeln,
    e.menge * IF(e.einheit = 'gebinde', IFNULL(a.ve_inhalt, 1), 1)
      - (SELECT COALESCE(SUM(r.menge * IF(r.einheit = 'gebinde', IFNULL(a.ve_inhalt, 1), 1)), 0)
           FROM rueckgabe r
          WHERE r.entnahme_id = e.entnahme_id) AS offen_einzeln,
    (SELECT MAX(r.datum) FROM rueckgabe r WHERE r.entnahme_id = e.entnahme_id) AS letzte_rueckgabe
FROM entnahme e
JOIN artikel a ON a.artikel_id = e.artikel_id;

CREATE OR REPLACE VIEW v_bewegung AS
SELECT
    'abgang'                AS richtung,
    e.entnahme_id           AS bewegung_id,
    e.entnahme_id,
    e.artikel_id,
    e.menge,
    e.einheit,
    e.mhd,
    CAST(e.grund AS CHAR)   AS grund,
    e.entnommen_am          AS datum,
    e.person,
    e.bezug,
    e.notiz,
    e.erfasst_von,
    e.erfasst_am
FROM entnahme e
UNION ALL
SELECT
    'zugang',
    z.zugang_id,
    NULL,
    z.artikel_id,
    z.menge,
    z.einheit,
    z.mhd,
    CAST(z.grund AS CHAR),
    z.datum,
    z.person,
    z.bezug,
    z.notiz,
    z.erfasst_von,
    z.erfasst_am
FROM zugang z
UNION ALL
SELECT
    'zugang',
    r.rueckgabe_id,
    r.entnahme_id,
    e.artikel_id,
    r.menge,
    r.einheit,
    e.mhd,
    'ruecklauf',
    r.datum,
    e.person,
    e.bezug,
    r.notiz,
    r.erfasst_von,
    r.erfasst_am
FROM rueckgabe r
JOIN entnahme e ON e.entnahme_id = r.entnahme_id;

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
    a.soll_bestand,
    (a.menge * IFNULL(a.ve_inhalt, 1) + a.lose) AS gesamt_einzeln,
    GREATEST(0, IFNULL(a.soll_bestand, 0) - (a.menge * IFNULL(a.ve_inhalt, 1) + a.lose)) AS fehlmenge,
    (a.soll_bestand IS NOT NULL
     AND (a.menge * IFNULL(a.ve_inhalt, 1) + a.lose) < a.soll_bestand) AS unter_soll,
    (a.ve_inhalt IS NOT NULL) AS hat_gebinde,
    a.faellig_am,
    a.faellig_typ,
    a.mit_chargen,
    (SELECT COUNT(*) FROM charge c WHERE c.artikel_id = a.artikel_id) AS anzahl_chargen,
    -- "01.03.2027: 20 Flaschen · 01.06.2027: 40 Flaschen" – für Listen,
    -- in denen sonst nur das früheste MHD stünde.
    (SELECT GROUP_CONCAT(
                CONCAT(DATE_FORMAT(c.mhd, '%d.%m.%Y'), ': ', c.menge, ' ', IFNULL(a.ve_einheit, 'Stück'))
                ORDER BY c.mhd SEPARATOR ' · ')
       FROM charge c
      WHERE c.artikel_id = a.artikel_id) AS chargen_text,
    a.notiz,
    a.kategorie_id,
    kat.bezeichnung AS kategorie,
    a.kiste_id,
    vk.kiste_nr,
    -- Ort: Kiste hat Vorrang, dann direktes Fach, sonst frei.
    COALESCE(vk.lager, vfa.lager) AS lager,
    COALESCE(vk.regal, vfa.regal) AS regal,
    COALESCE(vk.fach,  vfa.fach)  AS fach,
    a.standort_frei,
    a.fach_id AS artikel_fach_id,
    (a.kiste_id IS NULL AND a.fach_id IS NULL) AS ohne_kiste,
    CASE
        WHEN a.kiste_id IS NOT NULL      THEN vk.lagerort_text
        WHEN a.fach_id  IS NOT NULL      THEN vfa.pfad
        WHEN a.standort_frei IS NOT NULL THEN a.standort_frei
        ELSE NULL
    END AS lagerort_text,
    -- Sortierschlüssel, damit ORDER BY über den Ort weiter funktioniert.
    COALESCE(vk.s_lager, vfa.s_lager) AS s_lager,
    COALESCE(vk.s_regal, vfa.s_regal) AS s_regal,
    COALESCE(vk.s_fach,  vfa.s_fach)  AS s_fach,
    DATEDIFF(a.faellig_am, CURDATE()) AS tage_bis_faellig,
    (SELECT COALESCE(SUM(ve.offen_einzeln), 0)
       FROM v_entnahme ve
      WHERE ve.artikel_id = a.artikel_id
        AND ve.grund = 'verliehen') AS verliehen_offen,
    (SELECT GROUP_CONCAT(
                CONCAT(f.bezeichnung, ': ', w.wert,
                       IF(f.einheit IS NULL OR f.einheit = '', '', CONCAT(' ', f.einheit)))
                ORDER BY f.reihenfolge, f.bezeichnung SEPARATOR ' · ')
       FROM artikel_feldwert w
       JOIN feld f ON f.feld_id = w.feld_id
      WHERE w.artikel_id = a.artikel_id
        AND w.wert <> '') AS zusatzfelder
FROM artikel a
LEFT JOIN v_kiste   vk  ON vk.kiste_id     = a.kiste_id
LEFT JOIN v_fach    vfa ON vfa.fach_id     = a.fach_id
LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id;
