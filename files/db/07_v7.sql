-- =====================================================================
-- Lagerverwaltung – Migration v6 -> v7
--
-- Bringt: Inventur zählt Gebinde und lose Stücke getrennt.
--
--   docker compose exec -T db mariadb -u root -p"…" lager < db/07_v7.sql
--
-- Wiederholbar.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Inventurpositionen um die Gebindezählung erweitern
--
-- Bisher gab es nur soll_menge und ist_menge. Bei Gebindeartikeln war
-- damit nicht klar, ob "8" acht Kisten oder acht Flaschen meint – zwei
-- Zähler hätten unterschiedliche Zahlen eingetragen, und die Differenz
-- wäre reine Glückssache gewesen.
--
-- Die Gebindeangaben werden wie Name und Lagerort als Kopie
-- mitgeführt: eine abgeschlossene Inventur ist ein Beleg und muss
-- lesbar bleiben, auch wenn die Verpackungseinheit später geändert
-- wird.
-- ---------------------------------------------------------------------
ALTER TABLE inventur_position
    ADD COLUMN IF NOT EXISTS soll_lose  INT UNSIGNED NOT NULL DEFAULT 0 AFTER soll_menge;

ALTER TABLE inventur_position
    ADD COLUMN IF NOT EXISTS ist_lose   INT UNSIGNED     NULL AFTER ist_menge;

ALTER TABLE inventur_position
    ADD COLUMN IF NOT EXISTS ve_inhalt  INT UNSIGNED     NULL AFTER lagerort;

ALTER TABLE inventur_position
    ADD COLUMN IF NOT EXISTS ve_einheit VARCHAR(30)      NULL AFTER ve_inhalt;

ALTER TABLE inventur_position
    ADD COLUMN IF NOT EXISTS gebinde_einheit VARCHAR(30) NULL AFTER ve_einheit;

-- ---------------------------------------------------------------------
-- View für die Auswertung
--
-- Rechnet Soll und Ist in Einzelstücke um. Nur so sind sie
-- vergleichbar: fehlt eine halbe Kiste, ist die Differenz in Flaschen
-- die Zahl, die zählt.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_inventur_position AS
SELECT
    p.*,
    (p.soll_menge * IFNULL(p.ve_inhalt, 1) + p.soll_lose) AS soll_gesamt,
    CASE
        WHEN p.ist_menge IS NULL THEN NULL
        ELSE p.ist_menge * IFNULL(p.ve_inhalt, 1) + IFNULL(p.ist_lose, 0)
    END AS ist_gesamt,
    CASE
        WHEN p.ist_menge IS NULL THEN NULL
        ELSE (p.ist_menge * IFNULL(p.ve_inhalt, 1) + IFNULL(p.ist_lose, 0))
           - (p.soll_menge * IFNULL(p.ve_inhalt, 1) + p.soll_lose)
    END AS differenz_gesamt,
    (p.ve_inhalt IS NOT NULL) AS hat_gebinde
FROM inventur_position p;
