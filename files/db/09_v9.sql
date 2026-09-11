-- =====================================================================
-- Lagerverwaltung – Migration v8 -> v9
--
-- Bringt: Sollbestand je Artikel und die daraus abgeleitete Fehlmenge.
--
--   docker compose exec -T db mariadb -u root -p"…" lager < db/09_v9.sql
--
-- Wiederholbar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Sollbestand
--
-- NULL heißt: dieser Artikel wird nicht überwacht. Nur wenige Artikel
-- brauchen einen Sollbestand – Verpflegung, Verbandmaterial, Batterien.
-- Ein Pflichtfeld daraus zu machen würde bedeuten, dass jemand für
-- jeden Kabelbinder eine Zahl erfinden muss.
--
-- Gezählt wird in EINZELSTÜCKEN, auch bei Gebinden: "mindestens 200
-- Flaschen" gilt unabhängig davon, ob sie in 10er- oder 24er-Kisten
-- geliefert werden, und der Vergleich läuft gegen gesamt_einzeln.
-- ---------------------------------------------------------------------
ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS soll_bestand INT UNSIGNED NULL AFTER lose;

-- ---------------------------------------------------------------------
-- View um Fehlmenge erweitern
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
    a.soll_bestand,
    (a.menge * IFNULL(a.ve_inhalt, 1) + a.lose) AS gesamt_einzeln,
    -- Wie viel fehlt zum Soll? 0, wenn genug da ist oder kein Soll gesetzt.
    GREATEST(0, IFNULL(a.soll_bestand, 0) - (a.menge * IFNULL(a.ve_inhalt, 1) + a.lose))
        AS fehlmenge,
    (a.soll_bestand IS NOT NULL
     AND (a.menge * IFNULL(a.ve_inhalt, 1) + a.lose) < a.soll_bestand) AS unter_soll,
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

-- ---------------------------------------------------------------------
-- Beispielwerte
-- ---------------------------------------------------------------------
UPDATE artikel SET soll_bestand = 240 WHERE artikelname LIKE 'Trinkwasser%'   AND soll_bestand IS NULL;
UPDATE artikel SET soll_bestand = 300 WHERE artikelname LIKE 'Mineralwasser%' AND soll_bestand IS NULL;
UPDATE artikel SET soll_bestand = 3   WHERE artikelname LIKE 'Erste-Hilfe%'   AND soll_bestand IS NULL;
UPDATE artikel SET soll_bestand = 100 WHERE artikelname LIKE 'Kabelbinder%'   AND soll_bestand IS NULL;
