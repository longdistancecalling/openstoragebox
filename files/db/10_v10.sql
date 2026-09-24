-- =====================================================================
-- Lagerverwaltung – Migration v9 -> v10
--
-- Bringt: echte Lagerort-Hierarchie Lager -> Regal -> Fach -> Kiste.
--
-- Der Lagerort ist bisher als Text (lager/regal/fach) an der Kiste
-- geklebt. Jetzt wird er eine eigene Struktur, auf die sowohl Kisten
-- als auch Artikel zeigen können. Damit lässt sich ein Artikel direkt
-- in ein Fach legen, ohne dass eine Kiste nötig ist.
--
-- WICHTIG: Diese Migration schreibt bestehende Daten um. Vor dem
-- Einspielen ein Backup ziehen (./backup.sh). Sie ist trotzdem
-- wiederholbar aufgebaut – der eigentliche Umbau läuft nur, solange
-- die alten Spalten noch existieren.
--
--   docker compose exec -T db mariadb -u root -p"…" lager < db/10_v10.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Die drei Ortsebenen
--
-- Selbstreferenz wäre flexibler, aber die Hierarchie ist bewusst auf
-- genau drei feste Ebenen festgelegt (Lager, Regal, Fach). Getrennte
-- Tabellen machen die Abfragen lesbar und die Fremdschlüssel eindeutig.
--
-- sortierung: kleine Zahl zuerst, damit die Reihenfolge im Regal der
-- Realität folgt und nicht dem Alphabet ("Fach 10" nicht vor "Fach 2").
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lager (
    lager_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(60)   NOT NULL,
    notiz       VARCHAR(255)      NULL,
    sortierung  SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (lager_id),
    UNIQUE KEY uq_lager_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS regal (
    regal_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    lager_id    INT UNSIGNED  NOT NULL,
    name        VARCHAR(60)   NOT NULL,
    notiz       VARCHAR(255)      NULL,
    sortierung  SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (regal_id),
    UNIQUE KEY uq_regal (lager_id, name),
    CONSTRAINT fk_regal_lager FOREIGN KEY (lager_id) REFERENCES lager (lager_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fach (
    fach_id     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    regal_id    INT UNSIGNED  NOT NULL,
    name        VARCHAR(60)   NOT NULL,
    notiz       VARCHAR(255)      NULL,
    sortierung  SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (fach_id),
    UNIQUE KEY uq_fach (regal_id, name),
    CONSTRAINT fk_fach_regal FOREIGN KEY (regal_id) REFERENCES regal (regal_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Kiste und Artikel bekommen einen Verweis aufs Fach
--
-- Die Kiste steht künftig IN einem Fach. Der Artikel kann entweder in
-- einer Kiste liegen (wie bisher) oder direkt in einem Fach – das ist
-- der neue Fall. standort_frei bleibt für alles, was in keine
-- Struktur passt (Zelte, Aggregate auf dem Hof).
-- ---------------------------------------------------------------------
ALTER TABLE kiste
    ADD COLUMN IF NOT EXISTS fach_id INT UNSIGNED NULL AFTER kiste_nr;

ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS fach_id INT UNSIGNED NULL AFTER kiste_id;

-- ---------------------------------------------------------------------
-- 3. Bestehende Textorte in die Hierarchie überführen
--
-- Läuft nur, wenn die alten Spalten noch da sind. Danach werden sie
-- entfernt (Schritt 5), sodass ein zweiter Durchlauf hier nichts mehr
-- findet und übersprungen wird.
-- ---------------------------------------------------------------------

-- Prozedur, weil das bedingte Umschreiben mehrere Schritte braucht.
DROP PROCEDURE IF EXISTS migriere_lagerorte;
DELIMITER //
CREATE PROCEDURE migriere_lagerorte()
BEGIN
    -- Nur ausführen, solange kiste.lager noch existiert.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'kiste' AND column_name = 'lager'
    ) THEN

        -- Lager aus allen vorkommenden Namen
        INSERT IGNORE INTO lager (name)
        SELECT DISTINCT lager FROM kiste WHERE lager IS NOT NULL AND lager <> '';

        -- Regale je Lager
        INSERT IGNORE INTO regal (lager_id, name)
        SELECT DISTINCT l.lager_id, k.regal
          FROM kiste k JOIN lager l ON l.name = k.lager
         WHERE k.regal IS NOT NULL AND k.regal <> '';

        -- Fächer je Regal
        INSERT IGNORE INTO fach (regal_id, name)
        SELECT DISTINCT r.regal_id, k.fach
          FROM kiste k
          JOIN lager l ON l.name = k.lager
          JOIN regal r ON r.lager_id = l.lager_id AND r.name = k.regal
         WHERE k.fach IS NOT NULL AND k.fach <> '';

        -- Kisten mit ihrem Fach verbinden
        UPDATE kiste k
          JOIN lager l ON l.name = k.lager
          JOIN regal r ON r.lager_id = l.lager_id AND r.name = k.regal
          JOIN fach  f ON f.regal_id = r.regal_id AND f.name = k.fach
           SET k.fach_id = f.fach_id;

    END IF;
END //
DELIMITER ;

CALL migriere_lagerorte();
DROP PROCEDURE migriere_lagerorte;

-- ---------------------------------------------------------------------
-- 4. Views neu bauen
--
-- v_fach liefert einen fertigen Pfad "Lager / Regal / Fach" samt der
-- Einzelteile und der Sortierschlüssel. Alle anderen Views und Seiten
-- ziehen ihre Ortsangaben ab jetzt hierüber.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_fach AS
SELECT
    f.fach_id,
    l.lager_id, l.name AS lager,
    r.regal_id, r.name AS regal,
    f.name AS fach,
    CONCAT_WS(' / ', l.name, r.name, f.name) AS pfad,
    l.sortierung AS s_lager, r.sortierung AS s_regal, f.sortierung AS s_fach
FROM fach f
JOIN regal r ON r.regal_id = f.regal_id
JOIN lager l ON l.lager_id = r.lager_id;

-- Kiste mit aufgelöstem Ort. Die Spalten lager/regal/fach heißen wie
-- früher, damit bestehende Abfragen weiterlaufen – nur ihre Quelle ist
-- jetzt die Hierarchie statt der gelöschten Textspalten.
CREATE OR REPLACE VIEW v_kiste AS
SELECT
    k.kiste_id, k.kiste_nr, k.notiz, k.fach_id,
    vf.lager, vf.regal, vf.fach, vf.pfad AS lagerort_text,
    vf.lager_id, vf.regal_id,
    vf.s_lager, vf.s_regal, vf.s_fach
FROM kiste k
LEFT JOIN v_fach vf ON vf.fach_id = k.fach_id;

-- Artikel-View: Ort kommt aus der Kiste ODER direkt aus dem Fach ODER
-- aus dem freien Standort. Genau eine Quelle greift je Artikel.
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
LEFT JOIN v_kiste   vk  ON vk.kiste_id     = a.kiste_id
LEFT JOIN v_fach    vfa ON vfa.fach_id     = a.fach_id
LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id;

-- ---------------------------------------------------------------------
-- 5. Alte Textspalten entfernen
--
-- Erst jetzt, nachdem alles überführt und die Views umgestellt sind.
-- Danach ist die Migration abgeschlossen; ein zweiter Durchlauf findet
-- die Spalten nicht mehr und die Prozedur oben tut nichts.
-- ---------------------------------------------------------------------
ALTER TABLE kiste
    DROP COLUMN IF EXISTS lager,
    DROP COLUMN IF EXISTS regal,
    DROP COLUMN IF EXISTS fach;

-- Kiste braucht ihren Ort jetzt über fach_id.
ALTER TABLE kiste
    ADD CONSTRAINT fk_kiste_fach FOREIGN KEY IF NOT EXISTS (fach_id)
        REFERENCES fach (fach_id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE artikel
    ADD CONSTRAINT fk_artikel_fach FOREIGN KEY IF NOT EXISTS (fach_id)
        REFERENCES fach (fach_id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Ein Artikel liegt an genau einem Ort: Kiste, Fach oder frei. Diese
-- Regel prüft die Anwendung (artikel.php), nicht die Datenbank: MariaDB
-- lehnt Spaltenreferenzen in einem per ALTER nachgezogenen CHECK ab
-- (Fehler 1901). Der bestehende chk_lagerort wird durch die neue
-- fach-Option ohnehin unpassend und wird hier entfernt.
ALTER TABLE artikel DROP CONSTRAINT IF EXISTS chk_lagerort;
