-- =====================================================================
-- Lagerverwaltung – Migration v7 -> v8
--
-- Bringt: Einstellungen in der Datenbank statt im Quelltext.
--
--   docker compose exec -T db mariadb -u root -p"…" lager < db/08_v8.sql
--
-- Wiederholbar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Einstellungen als Schlüssel-Wert-Paare
--
-- Bewusst allgemein gehalten statt einer Tabelle mit einer Spalte je
-- Einstellung: neue Einstellungen brauchen dann keine Migration mehr,
-- nur einen Eintrag in der Liste in einstellungen.php.
--
-- Der Preis ist, dass alle Werte Text sind. Bei einer Handvoll
-- Einstellungen ist das der bessere Tausch.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS einstellung (
    schluessel  VARCHAR(40)   NOT NULL,
    wert        VARCHAR(255)  NOT NULL DEFAULT '',
    geaendert_am TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    geaendert_von INT UNSIGNED    NULL,
    PRIMARY KEY (schluessel),
    CONSTRAINT fk_einstellung_benutzer
        FOREIGN KEY (geaendert_von) REFERENCES benutzer (benutzer_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Startwerte. INSERT IGNORE, damit ein zweiter Durchlauf bereits
-- geänderte Werte nicht wieder überschreibt.
INSERT IGNORE INTO einstellung (schluessel, wert) VALUES
    ('organisation',  'DRK KV Eisenach'),
    ('basis_url',     ''),
    ('warnung_tage',  '30');
