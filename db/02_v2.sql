-- =====================================================================
-- Lagerverwaltung – Migration v1 -> v2
--
-- Bringt: Inventarnummern, Artikel ohne Kiste, Entnahmeprotokoll,
--         Inventur.
--
-- Einspielen auf einem laufenden Stack:
--   docker compose exec -T db mariadb -u root -pDEINPASS lager < db/02_v2.sql
--
-- Das Skript ist wiederholbar: es prüft vor jeder Änderung, ob sie
-- schon vorhanden ist. Ein zweiter Durchlauf richtet keinen Schaden an.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Artikel erweitern
-- ---------------------------------------------------------------------

-- Inventarnummer: das Etikett, das am Artikel klebt.
-- Getrennt von artikel_id, weil eine Datenbank-ID nichts über den
-- Bestand aussagen darf und ihr auch niemand ein Label spendieren will.
ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS inventar_nr VARCHAR(20) NULL AFTER artikel_id;

-- Freier Standort für alles, was in keine Kiste passt:
-- Zelt, Feldbett-Stapel, Stromerzeuger, Anhänger.
ALTER TABLE artikel
    ADD COLUMN IF NOT EXISTS standort_frei VARCHAR(120) NULL AFTER kiste_id;

-- Kiste wird optional.
ALTER TABLE artikel
    MODIFY COLUMN kiste_id INT UNSIGNED NULL;

-- Bestehende Artikel bekommen eine Nummer aus ihrer ID.
UPDATE artikel
   SET inventar_nr = CONCAT('A-', LPAD(artikel_id, 5, '0'))
 WHERE inventar_nr IS NULL;

-- Erst jetzt eindeutig stellen – vorher wären alle NULL-Werte kollidiert.
ALTER TABLE artikel
    ADD UNIQUE KEY IF NOT EXISTS uq_artikel_inventar_nr (inventar_nr);

-- Entweder Kiste oder freier Standort, nie beides gleichzeitig.
-- Sonst stünde ein Artikel an zwei Orten und niemand wüsste, welcher gilt.
--
-- Stolperstein: MariaDB lehnt einen CHECK auf eine Spalte ab, die in
-- einem Fremdschlüssel mit ON UPDATE CASCADE steht ("Function or
-- expression 'kiste_id' cannot be used in the CHECK clause"). Da
-- kiste_id auf einen AUTO_INCREMENT-Schlüssel zeigt, der sich nie
-- ändert, ist CASCADE dort ohnehin wirkungslos – RESTRICT tut dasselbe
-- und macht den CHECK möglich.
ALTER TABLE artikel DROP FOREIGN KEY IF EXISTS fk_artikel_kiste;

ALTER TABLE artikel
    ADD CONSTRAINT fk_artikel_kiste
        FOREIGN KEY (kiste_id) REFERENCES kiste (kiste_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE artikel
    ADD CONSTRAINT IF NOT EXISTS chk_lagerort CHECK (
        kiste_id IS NULL OR standort_frei IS NULL
    );

-- ---------------------------------------------------------------------
-- 2. Entnahme
--
-- Jede Bewegung wird protokolliert. Vier Gründe sind endgültig,
-- 'verliehen' ist der einzige, bei dem Material zurückkommt – deshalb
-- die Spalte zurueck_am.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entnahme (
    entnahme_id   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    artikel_id    INT UNSIGNED    NOT NULL,
    menge         INT UNSIGNED    NOT NULL,
    grund         ENUM('einsatz','verschleiss','defekt','abgelaufen','verliehen') NOT NULL,
    entnommen_am  DATE            NOT NULL,
    person        VARCHAR(80)         NULL,
    bezug         VARCHAR(120)        NULL,  -- Einsatzstichwort, Übung, Empfänger
    notiz         VARCHAR(255)        NULL,
    zurueck_am    DATE                NULL,  -- nur bei 'verliehen'
    erfasst_am    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entnahme_id),
    KEY idx_entnahme_artikel (artikel_id),
    KEY idx_entnahme_datum (entnommen_am),
    KEY idx_entnahme_offen (grund, zurueck_am),
    CONSTRAINT fk_entnahme_artikel
        FOREIGN KEY (artikel_id) REFERENCES artikel (artikel_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_menge_positiv CHECK (menge > 0),
    -- Ein Rückgabedatum ergibt nur bei einer Leihe Sinn.
    CONSTRAINT chk_rueckgabe CHECK (zurueck_am IS NULL OR grund = 'verliehen')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Inventur
--
-- Die Positionen speichern Name und Lagerort als Kopie. Eine
-- abgeschlossene Inventur ist ein Beleg – sie muss auch dann noch
-- lesbar sein, wenn der Artikel später umbenannt oder umgelagert wird.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventur (
    inventur_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    bezeichnung       VARCHAR(120)  NOT NULL,
    begonnen_am       DATE          NOT NULL,
    abgeschlossen_am  DATE              NULL,
    notiz             VARCHAR(255)      NULL,
    PRIMARY KEY (inventur_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventur_position (
    inventur_id   INT UNSIGNED   NOT NULL,
    artikel_id    INT UNSIGNED   NOT NULL,
    inventar_nr   VARCHAR(20)        NULL,   -- Kopie zum Zeitpunkt der Zählung
    artikelname   VARCHAR(120)   NOT NULL,   -- Kopie
    lagerort      VARCHAR(160)       NULL,   -- Kopie
    soll_menge    INT            NOT NULL,
    ist_menge     INT                NULL,   -- NULL = noch nicht gezählt
    gezaehlt_am   DATETIME           NULL,
    bemerkung     VARCHAR(255)       NULL,
    PRIMARY KEY (inventur_id, artikel_id),
    KEY idx_pos_artikel (artikel_id),
    CONSTRAINT fk_pos_inventur
        FOREIGN KEY (inventur_id) REFERENCES inventur (inventur_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pos_artikel
        FOREIGN KEY (artikel_id) REFERENCES artikel (artikel_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. View neu bauen
--
-- lagerort_text liefert eine fertige Ortsangabe, egal ob der Artikel in
-- einer Kiste liegt oder frei steht. ohne_kiste dient als Sortierschlüssel,
-- damit freie Standorte hinter den Kisten einsortiert werden.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_artikel AS
SELECT
    a.artikel_id,
    a.inventar_nr,
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
    -- Offene Leihen: entnommen, aber noch nicht zurück.
    (SELECT COALESCE(SUM(e.menge), 0)
       FROM entnahme e
      WHERE e.artikel_id = a.artikel_id
        AND e.grund = 'verliehen'
        AND e.zurueck_am IS NULL) AS verliehen_offen
FROM artikel a
LEFT JOIN kiste     k   ON k.kiste_id      = a.kiste_id
LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id;

-- ---------------------------------------------------------------------
-- 5. Beispieldaten für v2
-- ---------------------------------------------------------------------

-- Artikel ohne Kiste
INSERT IGNORE INTO artikel (artikelname, kiste_id, standort_frei, kategorie_id, menge, faellig_am, faellig_typ, notiz)
SELECT 'Feldbett klappbar', NULL, 'Halle B, Stellplatz 3',
       (SELECT kategorie_id FROM kategorie WHERE bezeichnung = 'Verbrauchsmaterial' LIMIT 1),
       40, NULL, NULL, 'gestapelt auf Palette'
 WHERE NOT EXISTS (SELECT 1 FROM artikel WHERE artikelname = 'Feldbett klappbar');

INSERT IGNORE INTO artikel (artikelname, kiste_id, standort_frei, kategorie_id, menge, faellig_am, faellig_typ, notiz)
SELECT 'Stromerzeuger 5 kVA', NULL, 'Halle B, Tor 1',
       (SELECT kategorie_id FROM kategorie WHERE bezeichnung = 'Elektro' LIMIT 1),
       2, '2026-10-15', 'pruefung', 'DGUV V3, Betriebsstunden notieren'
 WHERE NOT EXISTS (SELECT 1 FROM artikel WHERE artikelname = 'Stromerzeuger 5 kVA');

-- Nummern für die neuen Zeilen nachziehen
UPDATE artikel
   SET inventar_nr = CONCAT('A-', LPAD(artikel_id, 5, '0'))
 WHERE inventar_nr IS NULL;
