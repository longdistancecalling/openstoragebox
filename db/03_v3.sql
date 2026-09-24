-- =====================================================================
-- Lagerverwaltung – Migration v2 -> v3
--
-- Bringt: Benutzerverwaltung mit Rollen, Protokollierung des
--         buchenden Benutzers bei Entnahmen.
--
-- Einspielen auf einem laufenden Stack:
--   docker compose exec -T db mariadb -u root -p"$(grep DB_ROOT_PASS .env | cut -d= -f2-)" \
--     lager < db/03_v3.sql
--
-- Wiederholbar. Es wird KEIN Benutzer angelegt – den ersten
-- Administrator erzeugt die Login-Seite beim ersten Aufruf, solange
-- die Tabelle leer ist. Damit gibt es kein Standardpasswort, das
-- jemand vergessen könnte zu ändern.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Benutzer
--
-- Rollen bauen aufeinander auf:
--   leser       sieht alles, darf exportieren
--   bearbeiter  darf zusätzlich anlegen, ändern, buchen, zählen
--   admin       darf zusätzlich löschen, Benutzer verwalten, importieren,
--               Inventuren abschließen
--
-- fehlversuche / gesperrt_bis: nach fünf falschen Passwörtern ist das
-- Konto 15 Minuten gesperrt. Das macht Passwort-Raten über das
-- Netz praktisch nutzlos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS benutzer (
    benutzer_id    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    benutzername   VARCHAR(40)    NOT NULL,
    anzeigename    VARCHAR(80)    NOT NULL,
    passwort_hash  VARCHAR(255)   NOT NULL,
    rolle          ENUM('leser','bearbeiter','admin') NOT NULL DEFAULT 'leser',
    aktiv          TINYINT(1)     NOT NULL DEFAULT 1,
    fehlversuche   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    gesperrt_bis   DATETIME           NULL,
    letzter_login  DATETIME           NULL,
    angelegt_am    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (benutzer_id),
    UNIQUE KEY uq_benutzername (benutzername)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Wer hat gebucht?
-- ON DELETE SET NULL: Benutzer werden ohnehin nur deaktiviert, nie
-- gelöscht – aber falls doch, bleibt die Buchung erhalten.
-- ---------------------------------------------------------------------
ALTER TABLE entnahme
    ADD COLUMN IF NOT EXISTS erfasst_von INT UNSIGNED NULL AFTER notiz;

-- Syntaxfalle: bei CHECK steht IF NOT EXISTS hinter CONSTRAINT, bei
-- FOREIGN KEY dagegen hinter FOREIGN KEY. Andersherum gibt es Fehler 1064.
ALTER TABLE entnahme
    ADD CONSTRAINT fk_entnahme_benutzer
        FOREIGN KEY IF NOT EXISTS (erfasst_von) REFERENCES benutzer (benutzer_id)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE inventur_position
    ADD COLUMN IF NOT EXISTS gezaehlt_von INT UNSIGNED NULL AFTER gezaehlt_am;

ALTER TABLE inventur_position
    ADD CONSTRAINT fk_pos_benutzer
        FOREIGN KEY IF NOT EXISTS (gezaehlt_von) REFERENCES benutzer (benutzer_id)
        ON DELETE SET NULL ON UPDATE CASCADE;
