-- =====================================================================
-- Lagerverwaltung – Migration v10 -> v11
--
-- Bringt: Rückgaben als eigene Buchungen – auch in Teilen und auch
-- nach einem Einsatz.
--
-- Bisher war "Einsatz" eine endgültige Entnahme, und eine Leihe kam
-- entweder ganz oder gar nicht zurück (ein einzelnes Datum
-- entnahme.zurueck_am). Jetzt gehören zu einer Entnahme beliebig viele
-- Rückgaben mit eigener Menge. Was von einer Einsatzentnahme nicht
-- zurückkommt, gilt als verbraucht – und genau diese Zahl braucht die
-- Nachbereitung eines Einsatzes.
--
-- Bestehende Rückgaben (zurueck_am gesetzt) werden als vollständige
-- Rückgabe übernommen, danach fällt die Spalte weg.
--
--   docker compose exec -T db mariadb -u root -p"…" lager < db/11_v11.sql
--
-- Wiederholbar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Rückgabe
--
-- Einheit wie bei Entnahme und Zugang: zurück können auch einzelne
-- Flaschen aus einer ausgegebenen Kiste kommen. Gerechnet wird deshalb
-- immer in Einzelstücken (siehe v_entnahme).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rueckgabe (
    rueckgabe_id  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    entnahme_id   INT UNSIGNED    NOT NULL,
    menge         INT UNSIGNED    NOT NULL,
    einheit       ENUM('gebinde','einzeln') NOT NULL DEFAULT 'einzeln',
    datum         DATE            NOT NULL,
    notiz         VARCHAR(255)        NULL,
    erfasst_von   INT UNSIGNED        NULL,
    erfasst_am    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rueckgabe_id),
    KEY idx_rueckgabe_entnahme (entnahme_id),
    KEY idx_rueckgabe_datum (datum),
    CONSTRAINT fk_rueckgabe_entnahme
        FOREIGN KEY (entnahme_id) REFERENCES entnahme (entnahme_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rueckgabe_benutzer
        FOREIGN KEY (erfasst_von) REFERENCES benutzer (benutzer_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_rueckgabe_menge CHECK (menge > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Bisherige Rückgaben übernehmen
--
-- Prozedur, weil das Kopieren nur laufen darf, solange es zurueck_am
-- noch gibt – beim zweiten Durchlauf ist die Spalte schon weg.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS migriere_rueckgaben;
DELIMITER //
CREATE PROCEDURE migriere_rueckgaben()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'entnahme' AND column_name = 'zurueck_am'
    ) THEN
        INSERT INTO rueckgabe (entnahme_id, menge, einheit, datum, notiz)
        SELECT e.entnahme_id, e.menge, e.einheit, e.zurueck_am, 'aus v10 übernommen'
          FROM entnahme e
         WHERE e.zurueck_am IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM rueckgabe r WHERE r.entnahme_id = e.entnahme_id);
    END IF;
END //
DELIMITER ;

CALL migriere_rueckgaben();
DROP PROCEDURE migriere_rueckgaben;

-- Erst jetzt die alte Spalte samt CHECK und Index entfernen. Der Index
-- über (grund, zurueck_am) wird durch einen über grund allein ersetzt.
ALTER TABLE entnahme DROP CONSTRAINT IF EXISTS chk_rueckgabe;
ALTER TABLE entnahme DROP INDEX IF EXISTS idx_entnahme_offen;
ALTER TABLE entnahme DROP COLUMN IF EXISTS zurueck_am;
ALTER TABLE entnahme ADD INDEX IF NOT EXISTS idx_entnahme_grund (grund);

-- ---------------------------------------------------------------------
-- 3. Views
--
-- v_entnahme rechnet je Entnahme in Einzelstücken: ausgegeben, zurück,
-- offen. "Offen" heißt bei einer Leihe "noch unterwegs", bei einem
-- Einsatz "verbraucht". Welche Gründe überhaupt rückgabefähig sind,
-- entscheidet die Anwendung (RUECKGABE_GRUENDE in config.php).
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_entnahme AS
SELECT
    e.entnahme_id,
    e.artikel_id,
    e.menge,
    e.einheit,
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

-- Rückgaben stehen im Protokoll als Zugang mit dem Grund "Rücklauf",
-- dazu die Entnahme, auf die sie sich beziehen. Person und Bezug kommen
-- von dort – wer das Material hatte und wofür.
CREATE OR REPLACE VIEW v_bewegung AS
SELECT
    'abgang'                AS richtung,
    e.entnahme_id           AS bewegung_id,
    e.entnahme_id,
    e.artikel_id,
    e.menge,
    e.einheit,
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
    'ruecklauf',
    r.datum,
    e.person,
    e.bezug,
    r.notiz,
    r.erfasst_von,
    r.erfasst_am
FROM rueckgabe r
JOIN entnahme e ON e.entnahme_id = r.entnahme_id;

-- Artikel-View aus v10, einzige Änderung: verliehen_offen zählt jetzt
-- in Einzelstücken und zieht Teilrückgaben ab.
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
