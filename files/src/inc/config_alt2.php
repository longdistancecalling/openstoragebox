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

const APP_NAME = 'Lagerverwaltung';

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

/**
 * Sortierreihenfolge für Lagerplätze.
 *
 * LENGTH() vor dem Wert sortiert "R2" vor "R10" – bei reiner
 * Textsortierung käme "R10" vor "R2", weil "1" < "2".
 */
const ORDER_LAGERPLATZ = 'lager, LENGTH(regal), regal, LENGTH(fach), fach, LENGTH(kiste_nr), kiste_nr';

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

/** Präfix der automatisch vergebenen Inventarnummern. */
const INVENTAR_PREFIX = 'A-';
