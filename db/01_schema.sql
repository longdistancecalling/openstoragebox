-- =====================================================================
-- Lagerverwaltung – Schema
-- MariaDB 11 / InnoDB / utf8mb4
-- Wird von docker-entrypoint-initdb.d beim ERSTEN Start automatisch
-- eingespielt. Bei Änderungen: Volume löschen (siehe README).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Kategorie
-- ---------------------------------------------------------------------
CREATE TABLE kategorie (
    kategorie_id  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    bezeichnung   VARCHAR(60)     NOT NULL,
    PRIMARY KEY (kategorie_id),
    UNIQUE KEY uq_kategorie_bezeichnung (bezeichnung)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Kiste = der eigentliche Lagerplatz
--
-- Die Kistennummer ist systemweit eindeutig (UNIQUE). Damit bestimmt
-- die Kiste ihren Ort, und der Ort steht genau einmal in der Datenbank.
-- Umlagern = ein UPDATE auf diese Zeile, nicht auf n Artikelzeilen.
-- ---------------------------------------------------------------------
CREATE TABLE kiste (
    kiste_id      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    kiste_nr      VARCHAR(20)     NOT NULL,
    lager         VARCHAR(30)     NOT NULL,
    regal         VARCHAR(30)     NOT NULL,
    fach          VARCHAR(30)     NOT NULL,
    notiz         VARCHAR(255)        NULL,
    PRIMARY KEY (kiste_id),
    UNIQUE KEY uq_kiste_nr (kiste_nr),
    KEY idx_lagerplatz (lager, regal, fach)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Artikel
--
-- faellig_am + faellig_typ statt zwei getrennter Datumsspalten:
-- "Was wird in 30 Tagen fällig?" ist damit eine einzige WHERE-Klausel.
-- Der CHECK erzwingt, dass beide Felder gemeinsam gesetzt oder
-- gemeinsam NULL sind.
-- ---------------------------------------------------------------------
CREATE TABLE artikel (
    artikel_id    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    artikelname   VARCHAR(120)    NOT NULL,
    kategorie_id  INT UNSIGNED        NULL,
    kiste_id      INT UNSIGNED    NOT NULL,
    menge         INT UNSIGNED    NOT NULL DEFAULT 1,
    faellig_am    DATE                NULL,
    faellig_typ   ENUM('ablauf','pruefung') NULL,
    notiz         VARCHAR(255)        NULL,
    angelegt_am   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (artikel_id),
    KEY idx_artikelname (artikelname),
    KEY idx_faellig_am (faellig_am),
    KEY fk_artikel_kiste (kiste_id),
    KEY fk_artikel_kategorie (kategorie_id),
    CONSTRAINT fk_artikel_kiste
        FOREIGN KEY (kiste_id) REFERENCES kiste (kiste_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_artikel_kategorie
        FOREIGN KEY (kategorie_id) REFERENCES kategorie (kategorie_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_faellig CHECK (
        (faellig_am IS NULL     AND faellig_typ IS NULL) OR
        (faellig_am IS NOT NULL AND faellig_typ IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- View: fasst den JOIN zusammen, den die Anwendung ständig braucht.
-- Spart in jedem Skript drei Zeilen SQL und hält die Joins an einer
-- Stelle wartbar.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_artikel AS
SELECT
    a.artikel_id,
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
    DATEDIFF(a.faellig_am, CURDATE()) AS tage_bis_faellig
FROM artikel a
JOIN kiste k        ON k.kiste_id     = a.kiste_id
LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id;

-- ---------------------------------------------------------------------
-- Beispieldaten
-- ---------------------------------------------------------------------
INSERT INTO kategorie (bezeichnung) VALUES
    ('Werkzeug'), ('Netzwerk'), ('Elektro'), ('Lebensmittel'),
    ('Verbrauchsmaterial'), ('Sicherheitstechnik');

INSERT INTO kiste (kiste_nr, lager, regal, fach, notiz) VALUES
    ('K001', 'Keller',  'R1', 'F1', NULL),
    ('K002', 'Keller',  'R1', 'F2', NULL),
    ('K003', 'Keller',  'R2', 'F1', 'schwer, unten halten'),
    ('K010', 'Keller',  'R10','F1', NULL),
    ('K011', 'Garage',  'R1', 'F1', NULL),
    ('K012', 'Garage',  'R1', 'F2', NULL),
    ('K020', 'Garage',  'R2', 'F3', NULL),
    ('K030', 'Dachboden','R1','F1', 'nur saisonal');

INSERT INTO artikel (artikelname, kategorie_id, kiste_id, menge, faellig_am, faellig_typ, notiz) VALUES
    ('Patchkabel Cat6 2m',       2, 1,  25, NULL, NULL, NULL),
    ('Patchkabel Cat6 0,5m',     2, 1,  40, NULL, NULL, NULL),
    ('Keystone-Modul Cat6a',     2, 2,  12, NULL, NULL, NULL),
    ('Feuerlöscher 6kg ABC',     6, 5,   1, '2026-11-30', 'pruefung', 'Wartung durch Fachbetrieb'),
    ('Erste-Hilfe-Kasten DIN13157', 6, 5, 1, '2027-03-31', 'ablauf', 'Verfallsdatum Inhalt'),
    ('Rauchmelder Q-Label',      6, 6,   4, '2029-06-01', 'ablauf', 'Batterie fest verbaut'),
    ('Akkuschrauber 18V',        1, 3,   1, NULL, NULL, NULL),
    ('Bohrer-Set HSS',           1, 3,   1, NULL, NULL, NULL),
    ('Verlängerungskabel 25m',   3, 7,   2, '2026-09-15', 'pruefung', 'DGUV V3'),
    ('Steckdosenleiste 6-fach',  3, 7,   5, '2026-09-15', 'pruefung', 'DGUV V3'),
    ('Notration Kekse',          4, 4,  10, '2026-10-01', 'ablauf', NULL),
    ('Trinkwasser 1,5l',         4, 4,  24, '2027-01-31', 'ablauf', NULL),
    ('Kabelbinder 200mm',        5, 2, 500, NULL, NULL, NULL),
    ('Kabelbinder 200mm',        5, 8, 200, NULL, NULL, 'Reserve Dachboden'),
    ('Isolierband schwarz',      5, 8,   6, NULL, NULL, NULL);
