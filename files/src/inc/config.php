<?php
declare(strict_types=1);

/**
 * Zentrale Konfiguration und PDO-Verbindung.
 * Wird von jeder Seite als erstes eingebunden.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const APP_NAME = 'OpenStorageBox';

/**
 * Trägerorganisation. Erscheint auf allen Ausdrucken – Etiketten,
 * Artikelliste, Zählliste. Steht hier zentral, damit sie sich an einer
 * Stelle ändern lässt.
 */
/*
 * Die folgenden Werte stehen seit v8 in der Datenbank und werden weiter
 * unten mit define() gesetzt – die Verwendung im übrigen Code bleibt
 * unverändert (ORGANISATION, BASIS_URL, WARNUNG_TAGE).
 *
 * Warum define() statt const: const wird beim Übersetzen ausgewertet
 * und kann keinen Wert aus einer Abfrage bekommen. define() läuft zur
 * Laufzeit und tut genau das.
 */

/**
 * Adresse, unter der die Anwendung erreichbar ist – ohne Schrägstrich
 * am Ende. Wird für die QR-Codes gebraucht: ein Scan mit der
 * Handykamera soll direkt die Buchungsseite öffnen.
 *
 * Leer lassen, wenn die Codes nur die Kennung enthalten sollen. Dann
 * funktioniert das Scannen weiterhin über die Scan-Seite, aber die
 * Kamera-App kann nichts damit anfangen.
 */
/* wird unten aus der Datenbank gesetzt */

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: 'db',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'lager'
);

try {
    $pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [
        // Fehler als Exception – nie stillschweigend weiterlaufen.
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Echte Prepared Statements statt Emulation durch den Treiber.
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
    http_response_code(503);
    exit('Die Datenbank ist nicht erreichbar. Details stehen im Container-Log.');
}

/* -------------------------------------------------------------------
   Einstellungen laden
   ------------------------------------------------------------------- */

/**
 * Liest alle Einstellungen als Schlüssel-Wert-Feld.
 *
 * Die Tabelle kann fehlen, wenn die Migration noch nicht eingespielt
 * ist – dann greifen die Standardwerte, und die Anwendung läuft
 * weiter, statt mit einem Fehler stehenzubleiben.
 */
function einstellungen_laden(PDO $pdo): array
{
    try {
        $zeilen = $pdo->query('SELECT schluessel, wert FROM einstellung')->fetchAll();
        return array_column($zeilen, 'wert', 'schluessel');
    } catch (PDOException $e) {
        return [];
    }
}

$einstellungen = einstellungen_laden($pdo);

define('ORGANISATION', $einstellungen['organisation'] ?? APP_NAME);
define('BASIS_URL',    $einstellungen['basis_url']    ?? '');
// Ab wann eine Fälligkeit als "bald" gilt.
define('WARNUNG_TAGE', max(1, (int) ($einstellungen['warnung_tage'] ?? 30)));

/**
 * Sortierreihenfolge für Lagerplätze.
 *
 * LENGTH() vor dem Wert sortiert "R2" vor "R10" – bei reiner
 * Textsortierung käme "R10" vor "R2", weil "1" < "2".
 */
// Seit v10 kommt der Ort aus der Hierarchie. Die Views liefern
// Sortierschlüssel (s_lager/s_regal/s_fach) plus die Namen; danach wird
// sortiert, sodass "Fach 2" vor "Fach 10" steht.
const ORDER_LAGERPLATZ = 's_lager, lager, s_regal, LENGTH(regal), regal, s_fach, LENGTH(fach), fach, LENGTH(kiste_nr), kiste_nr';
// Dieselbe Sortierung, aber mit Tabellenalias k. – für Abfragen, die
// v_kiste als k joinen (kisten.php, export.php). Wird gebraucht, weil
// die Spaltennamen zusammengesetzt sind (s_lager) und ein naives
// str_replace('lager','k.lager') sonst auch in s_lager hineingreift.
const ORDER_LAGERPLATZ_K = 'k.s_lager, k.lager, k.s_regal, LENGTH(k.regal), k.regal, k.s_fach, LENGTH(k.fach), k.fach, LENGTH(k.kiste_nr), k.kiste_nr';

/** Dasselbe für die Artikelliste: freie Standorte hinter die Kisten. */
const ORDER_LAGERORT = 'ohne_kiste, ' . ORDER_LAGERPLATZ . ', standort_frei';

/**
 * Entnahmegründe.
 *
 * 'verliehen' ist der einzige Grund, bei dem Material zurückkommt.
 * Die anderen vier sind endgültig – deshalb steht dieser Unterschied
 * an einer Stelle und wird überall daraus abgeleitet.
 */
const ENTNAHME_GRUENDE = [
    'einsatz'     => 'Einsatz',
    'verschleiss' => 'Verschleiß',
    'defekt'      => 'Defekt',
    'abgelaufen'  => 'Abgelaufen',
    'verliehen'   => 'Verliehen',
];

const GRUND_LEIHE = 'verliehen';

/** Gründe für einen Wareneingang. Spiegelbild der Entnahmegründe. */
const ZUGANG_GRUENDE = [
    'beschaffung' => 'Beschaffung',
    'spende'      => 'Spende',
    'ruecklauf'   => 'Rücklauf',
    'korrektur'   => 'Korrektur',
];

/** Präfix der automatisch vergebenen Inventarnummern. */
const INVENTAR_PREFIX = 'A-';

/* -------------------------------------------------------------------
   Hilfsfunktionen und Anmeldung.
   Ab hier ist auf jeder Seite außer login.php ein angemeldeter
   Benutzer garantiert – die Seiten selbst müssen das nicht mehr prüfen,
   nur noch die Rolle über erfordere().
   ------------------------------------------------------------------- */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
auth_erzwingen();
