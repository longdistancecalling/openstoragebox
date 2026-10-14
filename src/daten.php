<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Drei Funktionen auf einer Seite:
 *
 *  1. Gesamtexport als JSON   – alle Bestandsdaten in einer Datei,
 *                                für Umzug oder Sicherung über den Browser
 *  2. Gesamtimport aus JSON   – ersetzt ALLE Bestandsdaten (nur Admin)
 *  3. Artikelimport aus CSV   – Massenerfassung aus einer Tabellen-
 *                                kalkulation, mit Vorschau vor dem Schreiben
 *
 * Benutzer sind bewusst nicht Teil des Exports: Passwort-Hashes gehören
 * nicht in eine Datei, die man per Mail weiterschickt.
 */

const EXPORT_FORMAT  = 'lagerverwaltung';
// v4: Rückgaben als eigene Tabelle statt entnahme.zurueck_am.
const EXPORT_VERSION = 4;

/** Tabellen in der Reihenfolge, in der sie wegen Fremdschlüsseln geschrieben werden müssen. */
// Reihenfolge = FK-Reihenfolge fürs Schreiben (Eltern vor Kind). Die
// Ortshierarchie steht vor der Kiste, weil kiste.fach_id darauf zeigt.
const EXPORT_TABELLEN = ['kategorie', 'lager', 'regal', 'fach', 'kiste', 'artikel', 'entnahme', 'rueckgabe', 'inventur', 'inventur_position'];

// Steht bewusst hier oben: PHP zieht Funktionsdefinitionen beim Laden
// nach vorn, Konstanten aber nicht. Eine Konstante unterhalb des Codes,
// der sie benutzt, ist zum Zeitpunkt des Aufrufs noch nicht definiert.
/** Spaltennamen, wie sie in der Kopfzeile stehen dürfen (klein, ohne Umlaute). */
const CSV_SPALTEN = [
    'artikelname' => ['artikelname', 'artikel', 'name', 'bezeichnung'],
    'inventar_nr' => ['inventar_nr', 'inventarnummer', 'nummer', 'nr', 'inv-nr'],
    'kiste_nr'    => ['kiste_nr', 'kiste', 'kistennummer', 'box'],
    'lager'       => ['lager'],
    'regal'       => ['regal'],
    'fach'        => ['fach'],
    'standort'    => ['standort', 'standort_frei', 'freier standort', 'lagerort'],
    'kategorie'   => ['kategorie', 'gruppe'],
    'menge'       => ['menge', 'bestand', 'anzahl', 'stueck', 'stück'],
    'faellig_am'  => ['faellig_am', 'faellig am', 'fällig am', 'datum', 'ablaufdatum', 'pruefdatum', 'prüfdatum'],
    'faellig_typ' => ['faellig_typ', 'art', 'typ', 'datumsart'],
    'notiz'       => ['notiz', 'bemerkung', 'hinweis'],
];

$fehler   = [];
$vorschau = null;

/* ===================================================================
   1. JSON-Export
   =================================================================== */
if (($_GET['aktion'] ?? '') === 'json_export') {
    $paket = [
        'format'        => EXPORT_FORMAT,
        'version'       => EXPORT_VERSION,
        'exportiert_am' => date('c'),
        'organisation'  => ORGANISATION,
        'exportiert_von'=> benutzer()['benutzername'],
    ];
    foreach (EXPORT_TABELLEN as $t) {
        $paket[$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll();
    }
    // erfasst_von / gezaehlt_von zeigen auf Benutzer, die im Ziel nicht existieren.
    foreach ($paket['entnahme'] as &$z)          { unset($z['erfasst_von']); }
    foreach ($paket['rueckgabe'] as &$z)         { unset($z['erfasst_von']); }
    foreach ($paket['inventur_position'] as &$z) { unset($z['gezaehlt_von']); }
    unset($z);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="lager_export_' . date('Y-m-d_Hi') . '.json"');
    echo json_encode($paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ===================================================================
   POST-Aktionen
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    /* ---- 2. JSON-Import -------------------------------------------- */
    if ($aktion === 'json_import') {
        erfordere('admin');

        $datei = $_FILES['datei'] ?? null;
        if (!$datei || $datei['error'] !== UPLOAD_ERR_OK) {
            $fehler[] = 'Keine Datei hochgeladen.';
        } elseif (($_POST['bestaetigung'] ?? '') !== 'ERSETZEN') {
            $fehler[] = 'Zur Bestätigung muss im Feld ERSETZEN stehen.';
        } else {
            $paket = json_decode((string) file_get_contents($datei['tmp_name']), true);

            if (!is_array($paket) || ($paket['format'] ?? '') !== EXPORT_FORMAT) {
                $fehler[] = 'Das ist keine Exportdatei dieser Anwendung.';
            } elseif ((int) ($paket['version'] ?? 0) > EXPORT_VERSION) {
                $fehler[] = 'Die Datei stammt aus einer neueren Version und kann hier nicht gelesen werden.';
            } else {
                // Dateien vor v4 kennen keine Tabelle rueckgabe; dort steht
                // eine abgeschlossene Leihe als entnahme.zurueck_am. Daraus
                // wird je eine vollständige Rückgabe, sonst wären nach dem
                // Import alle zurückgegebenen Leihen wieder offen.
                if ((int) ($paket['version'] ?? 0) < 4 && empty($paket['rueckgabe'])) {
                    $paket['rueckgabe'] = [];
                    foreach ($paket['entnahme'] ?? [] as $z) {
                        if (!empty($z['zurueck_am'])) {
                            $paket['rueckgabe'][] = [
                                'entnahme_id' => $z['entnahme_id'],
                                'menge'       => $z['menge'],
                                'einheit'     => $z['einheit'] ?? 'einzeln',
                                'datum'       => $z['zurueck_am'],
                                'notiz'       => 'aus älterem Export übernommen',
                            ];
                        }
                    }
                }

                try {
                    $pdo->beginTransaction();
                    // Fremdschlüssel während des Leerens und Schreibens aus,
                    // damit die Reihenfolge innerhalb einer Tabelle keine Rolle
                    // spielt. Am Ende wieder an – MariaDB prüft dann nicht
                    // rückwirkend, deshalb schreiben wir in FK-Reihenfolge.
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    foreach (array_reverse(EXPORT_TABELLEN) as $t) {
                        $pdo->exec("DELETE FROM `$t`");
                    }

                    $zaehler = [];
                    foreach (EXPORT_TABELLEN as $t) {
                        $zeilen = $paket[$t] ?? [];
                        $zaehler[$t] = 0;
                        if (!$zeilen) {
                            continue;
                        }
                        // Spalten aus der Zieltabelle holen; nur die schreiben,
                        // die es dort gibt. So verkraftet der Import Dateien
                        // aus älteren Versionen mit weniger Spalten.
                        $spalten = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
                        $nutzbar = array_values(array_intersect($spalten, array_keys($zeilen[0])));
                        $ph      = implode(',', array_fill(0, count($nutzbar), '?'));
                        $cols    = implode(',', array_map(fn($c) => "`$c`", $nutzbar));
                        $ins     = $pdo->prepare("INSERT INTO `$t` ($cols) VALUES ($ph)");
                        foreach ($zeilen as $z) {
                            $ins->execute(array_map(fn($c) => $z[$c] ?? null, $nutzbar));
                            $zaehler[$t]++;
                        }
                    }
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    $pdo->commit();

                    $txt = [];
                    foreach ($zaehler as $t => $n) { $txt[] = "$n $t"; }
                    flash('Import abgeschlossen: ' . implode(', ', $txt) . '.');
                    redirect('index.php');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    error_log('JSON-Import: ' . $e->getMessage());
                    $fehler[] = 'Import abgebrochen, nichts wurde verändert: ' . $e->getMessage();
                }
            }
        }
    }

    /* ---- 3a. CSV hochladen und prüfen ------------------------------ */
    if ($aktion === 'csv_pruefen') {
        erfordere('bearbeiter');
        $datei = $_FILES['datei'] ?? null;
        if (!$datei || $datei['error'] !== UPLOAD_ERR_OK) {
            $fehler[] = 'Keine Datei hochgeladen.';
        } else {
            // Datei für den zweiten Schritt ablegen. Nur der Token wandert
            // in die Session, nicht der Inhalt.
            $token = bin2hex(random_bytes(16));
            $pfad  = sys_get_temp_dir() . "/lager_import_$token.csv";
            move_uploaded_file($datei['tmp_name'], $pfad);
            $_SESSION['csv_import'] = ['token' => $token, 'zeit' => time()];
            $vorschau = csv_analysieren($pdo, $pfad);
            $vorschau['token'] = $token;
        }
    }

    /* ---- 3b. CSV schreiben ----------------------------------------- */
    if ($aktion === 'csv_import') {
        erfordere('bearbeiter');
        $token  = (string) ($_POST['token'] ?? '');
        $sess   = $_SESSION['csv_import'] ?? null;
        $pfad   = sys_get_temp_dir() . "/lager_import_$token.csv";

        if (!$sess || !hash_equals($sess['token'], $token) || !is_file($pfad) || time() - $sess['zeit'] > 1800) {
            $fehler[] = 'Die Vorschau ist abgelaufen. Bitte die Datei erneut hochladen.';
        } else {
            $analyse = csv_analysieren($pdo, $pfad);
            if ($analyse['fehler']) {
                $fehler[] = 'Die Datei enthält noch Fehler. Import nicht durchgeführt.';
                $vorschau = $analyse;
                $vorschau['token'] = $token;
            } else {
                try {
                    $pdo->beginTransaction();
                    $n = csv_schreiben($pdo, $analyse['zeilen'], (int) benutzer()['benutzer_id']);
                    $pdo->commit();
                    unlink($pfad);
                    unset($_SESSION['csv_import']);
                    flash("{$n['neu']} Artikel angelegt, {$n['aktualisiert']} aktualisiert, "
                        . "{$n['kisten']} Kisten und {$n['kategorien']} Kategorien neu.");
                    redirect('index.php');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('CSV-Import: ' . $e->getMessage());
                    $fehler[] = 'Import abgebrochen, nichts wurde verändert: ' . $e->getMessage();
                }
            }
        }
    }
}

/* ===================================================================
   CSV-Funktionen
   =================================================================== */


function csv_normalisieren(string $s): string
{
    $s = mb_strtolower(trim($s));
    return str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $s);
}

/**
 * Datum aus TT.MM.JJJJ oder JJJJ-MM-TT, oder null wenn ungültig.
 *
 * Falle: createFromFormat() nimmt den 31.02. klaglos an und rollt ihn
 * zum 3. März weiter. Erst getLastErrors() verrät den Überlauf – deshalb
 * die zusätzliche Prüfung, sonst landen Fantasiedaten im Bestand.
 */
function csv_datum(string $s): ?string
{
    foreach (['!d.m.Y', '!Y-m-d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $s);
        $err = DateTimeImmutable::getLastErrors();
        $sauber = $err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0);
        if ($dt !== false && $sauber) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

/** Liest die Datei, ordnet Spalten zu, prüft jede Zeile. Schreibt nichts. */
function csv_analysieren(PDO $pdo, string $pfad): array
{
    $inhalt = (string) file_get_contents($pfad);
    // BOM entfernen (Excel schreibt eins), Windows-Zeilenenden vereinheitlichen.
    $inhalt = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt);
    if (!mb_check_encoding($inhalt, 'UTF-8')) {
        // Excel exportiert ohne BOM gern in Windows-1252.
        $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
    }
    $inhalt = str_replace(["\r\n", "\r"], "\n", $inhalt);

    $zeilenRoh = array_values(array_filter(explode("\n", $inhalt), fn($l) => trim($l) !== ''));
    if (count($zeilenRoh) < 2) {
        return ['fehler' => ['Die Datei enthält keine Datenzeilen.'], 'zeilen' => [], 'zuordnung' => []];
    }

    // Trennzeichen: das, was in der Kopfzeile häufiger vorkommt.
    $trenner = substr_count($zeilenRoh[0], ';') >= substr_count($zeilenRoh[0], ',') ? ';' : ',';

    $kopf = array_map('csv_normalisieren', str_getcsv($zeilenRoh[0], $trenner, '"', ''));
    $zuordnung = [];
    foreach (CSV_SPALTEN as $feld => $namen) {
        foreach ($kopf as $i => $k) {
            if (in_array($k, array_map('csv_normalisieren', $namen), true)) {
                $zuordnung[$feld] = $i;
                break;
            }
        }
    }

    $fehler = [];
    if (!isset($zuordnung['artikelname'])) {
        $fehler[] = 'Keine Spalte für den Artikelnamen gefunden. Erwartet wird z. B. „Artikel“ oder „Artikelname“ in der Kopfzeile.';
        return ['fehler' => $fehler, 'zeilen' => [], 'zuordnung' => $zuordnung, 'kopf' => $kopf];
    }

    // Nachschlagetabellen einmal laden, nicht pro Zeile abfragen.
    $kisten     = array_column($pdo->query('SELECT kiste_id, kiste_nr FROM kiste')->fetchAll(), 'kiste_id', 'kiste_nr');
    $kategorien = array_column($pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie')->fetchAll(), 'kategorie_id', 'bezeichnung');
    $vorhanden  = array_column($pdo->query('SELECT artikel_id, inventar_nr FROM artikel WHERE inventar_nr IS NOT NULL')->fetchAll(), 'artikel_id', 'inventar_nr');

    $zeilen = [];
    $neueKisten = []; $neueKategorien = [];

    for ($n = 1; $n < count($zeilenRoh); $n++) {
        $w = str_getcsv($zeilenRoh[$n], $trenner, '"', '');
        $get = fn(string $feld) => isset($zuordnung[$feld]) ? trim((string) ($w[$zuordnung[$feld]] ?? '')) : '';
        $z = ['zeile' => $n + 1, 'probleme' => []];

        $z['artikelname'] = $get('artikelname');
        if ($z['artikelname'] === '') {
            $z['probleme'][] = 'Artikelname fehlt';
        }

        $z['inventar_nr'] = $get('inventar_nr');
        $z['artikel_id']  = $z['inventar_nr'] !== '' && isset($vorhanden[$z['inventar_nr']])
                          ? (int) $vorhanden[$z['inventar_nr']] : null;

        // Lagerort: Kiste hat Vorrang, sonst freier Standort, sonst keiner.
        $z['kiste_nr'] = $get('kiste_nr');
        $z['standort'] = $get('standort');
        $z['kiste_neu'] = null;
        if ($z['kiste_nr'] !== '') {
            if (!isset($kisten[$z['kiste_nr']]) && !isset($neueKisten[$z['kiste_nr']])) {
                $l = $get('lager'); $r = $get('regal'); $f = $get('fach');
                if ($l !== '' && $r !== '' && $f !== '') {
                    $neueKisten[$z['kiste_nr']] = ['lager' => $l, 'regal' => $r, 'fach' => $f];
                } else {
                    $z['probleme'][] = "Kiste {$z['kiste_nr']} unbekannt – zum Anlegen Lager, Regal und Fach angeben";
                }
            }
            if (isset($neueKisten[$z['kiste_nr']])) {
                $z['kiste_neu'] = $neueKisten[$z['kiste_nr']];
            }
            $z['standort'] = '';
        }

        $z['kategorie'] = $get('kategorie');
        $z['kategorie_neu'] = $z['kategorie'] !== '' && !isset($kategorien[$z['kategorie']]);
        if ($z['kategorie_neu']) {
            $neueKategorien[$z['kategorie']] = true;
        }

        $m = $get('menge');
        $z['menge'] = $m === '' ? 1 : (ctype_digit($m) ? (int) $m : null);
        if ($z['menge'] === null) {
            $z['probleme'][] = "Menge „{$m}“ ist keine ganze Zahl";
        }

        $d = $get('faellig_am');
        $z['faellig_am'] = null;
        if ($d !== '') {
            $z['faellig_am'] = csv_datum($d);
            if ($z['faellig_am'] === null) {
                $z['probleme'][] = "Datum „{$d}“ ungültig (TT.MM.JJJJ oder JJJJ-MM-TT)";
            }
        }

        $t = csv_normalisieren($get('faellig_typ'));
        $z['faellig_typ'] = match (true) {
            $t === ''                                       => null,
            str_starts_with($t, 'abl') || str_starts_with($t, 'lae') || $t === 'mhd' => 'ablauf',
            str_starts_with($t, 'pru') || str_starts_with($t, 'pruef')               => 'pruefung',
            default => 'unbekannt',
        };
        if ($z['faellig_typ'] === 'unbekannt') {
            $z['probleme'][] = "Datumsart „{$get('faellig_typ')}“ unbekannt (Ablauf oder Prüfung)";
            $z['faellig_typ'] = null;
        }
        if ($z['faellig_am'] !== null && $z['faellig_typ'] === null) {
            $z['faellig_typ'] = 'ablauf';   // Datum ohne Art: Ablauf ist der häufigere Fall
            $z['hinweis'] = 'Datumsart fehlte, als Ablaufdatum übernommen';
        }
        if ($z['faellig_am'] === null) {
            $z['faellig_typ'] = null;
        }

        $z['notiz'] = $get('notiz');
        $zeilen[] = $z;
    }

    foreach ($zeilen as $z) {
        foreach ($z['probleme'] as $p) {
            $fehler[] = "Zeile {$z['zeile']}: $p";
        }
    }

    return [
        'fehler' => $fehler, 'zeilen' => $zeilen, 'zuordnung' => $zuordnung, 'kopf' => $kopf,
        'trenner' => $trenner, 'neue_kisten' => count($neueKisten), 'neue_kategorien' => count($neueKategorien),
        'neu' => count(array_filter($zeilen, fn($z) => $z['artikel_id'] === null)),
        'aktualisiert' => count(array_filter($zeilen, fn($z) => $z['artikel_id'] !== null)),
    ];
}

/** Schreibt geprüfte Zeilen. Muss in einer Transaktion laufen. */
function csv_schreiben(PDO $pdo, array $zeilen, int $benutzerId): array
{
    $n = ['neu' => 0, 'aktualisiert' => 0, 'kisten' => 0, 'kategorien' => 0];

    $kisten     = array_column($pdo->query('SELECT kiste_id, kiste_nr FROM kiste')->fetchAll(), 'kiste_id', 'kiste_nr');
    $kategorien = array_column($pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie')->fetchAll(), 'kategorie_id', 'bezeichnung');

    $insKiste = $pdo->prepare('INSERT INTO kiste (kiste_nr, fach_id) VALUES (?, ?)');
    $ortCache = [];  // Lager|Regal|Fach -> fach_id, innerhalb dieses Laufs
    $insKat   = $pdo->prepare('INSERT INTO kategorie (bezeichnung) VALUES (?)');
    $insArt   = $pdo->prepare(
        'INSERT INTO artikel (artikelname, kiste_id, standort_frei, kategorie_id, menge, faellig_am, faellig_typ, notiz)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $updArt   = $pdo->prepare(
        'UPDATE artikel SET artikelname=?, kiste_id=?, standort_frei=?, kategorie_id=?, menge=?, faellig_am=?, faellig_typ=?, notiz=?
         WHERE artikel_id=?'
    );
    $setNr    = $pdo->prepare('UPDATE artikel SET inventar_nr = ? WHERE artikel_id = ?');

    foreach ($zeilen as $z) {
        $kisteId = null;
        if ($z['kiste_nr'] !== '') {
            if (!isset($kisten[$z['kiste_nr']])) {
                $k = $z['kiste_neu'];
                $fachId = fach_id_aufloesen($pdo, $k['lager'], $k['regal'], $k['fach'], $ortCache);
                $insKiste->execute([$z['kiste_nr'], $fachId]);
                $kisten[$z['kiste_nr']] = (int) $pdo->lastInsertId();
                $n['kisten']++;
            }
            $kisteId = (int) $kisten[$z['kiste_nr']];
        }

        $katId = null;
        if ($z['kategorie'] !== '') {
            if (!isset($kategorien[$z['kategorie']])) {
                $insKat->execute([$z['kategorie']]);
                $kategorien[$z['kategorie']] = (int) $pdo->lastInsertId();
                $n['kategorien']++;
            }
            $katId = (int) $kategorien[$z['kategorie']];
        }

        $werte = [
            $z['artikelname'], $kisteId, $z['standort'] !== '' ? $z['standort'] : null, $katId,
            $z['menge'], $z['faellig_am'], $z['faellig_typ'], $z['notiz'] !== '' ? $z['notiz'] : null,
        ];

        if ($z['artikel_id'] !== null) {
            $updArt->execute([...$werte, $z['artikel_id']]);
            $n['aktualisiert']++;
        } else {
            $insArt->execute($werte);
            $id = (int) $pdo->lastInsertId();
            $setNr->execute([$z['inventar_nr'] !== '' ? $z['inventar_nr'] : inventar_nr_aus_id($id), $id]);
            $n['neu']++;
        }
    }
    return $n;
}

/* ===================================================================
   Seite
   =================================================================== */
$anz = [];
foreach (EXPORT_TABELLEN as $t) {
    $anz[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

$titel = 'Daten';
$aktiv = 'daten';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf"><h1>Daten</h1></div>

<?php if ($fehler): ?>
  <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php if ($vorschau !== null && $vorschau['zeilen']): ?>
  <!-- ============ CSV-Vorschau ============ -->
  <h2 class="abschnitt">Vorschau des Artikelimports</h2>
  <?php if ($vorschau['fehler']): ?>
    <ul class="fehlerliste">
      <?php foreach (array_slice($vorschau['fehler'], 0, 25) as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
      <?php if (count($vorschau['fehler']) > 25): ?><li>… und <?= count($vorschau['fehler']) - 25 ?> weitere</li><?php endif; ?>
    </ul>
  <?php endif; ?>
  <div class="kennzahlen">
    <div class="kennzahl"><span class="kz-wert"><?= count($vorschau['zeilen']) ?></span><span class="kz-label">Zeilen</span></div>
    <div class="kennzahl"><span class="kz-wert"><?= $vorschau['neu'] ?></span><span class="kz-label">neu</span></div>
    <div class="kennzahl"><span class="kz-wert"><?= $vorschau['aktualisiert'] ?></span><span class="kz-label">aktualisiert</span></div>
    <div class="kennzahl"><span class="kz-wert"><?= $vorschau['neue_kisten'] ?></span><span class="kz-label">Kisten neu</span></div>
    <div class="kennzahl"><span class="kz-wert"><?= $vorschau['neue_kategorien'] ?></span><span class="kz-label">Kategorien neu</span></div>
    <div class="kennzahl<?= $vorschau['fehler'] ? ' kennzahl--rot' : '' ?>"><span class="kz-wert"><?= count($vorschau['fehler']) ?></span><span class="kz-label">Fehler</span></div>
  </div>

  <p class="hinweis">
    Erkannte Spalten: <?= h(implode(', ', array_keys($vorschau['zuordnung']))) ?>
    · Trennzeichen „<?= h($vorschau['trenner']) ?>“
  </p>

  <table class="tabelle">
    <thead><tr><th>Zeile</th><th>Artikel</th><th>Nr.</th><th>Lagerort</th><th>Kategorie</th><th style="text-align:right">Menge</th><th>Fällig</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($vorschau['zeilen'], 0, 200) as $z): ?>
      <tr<?= $z['probleme'] ? ' class="stufe-faellig"' : '' ?>>
        <td data-label="Zeile"><span class="invnr"><?= $z['zeile'] ?></span></td>
        <td data-label="Artikel"><span class="name"><?= h($z['artikelname']) ?></span>
          <?php if ($z['notiz']): ?><span class="notiz"><?= h($z['notiz']) ?></span><?php endif; ?></td>
        <td data-label="Nr."><span class="invnr"><?= h($z['inventar_nr'] ?: 'auto') ?></span></td>
        <td data-label="Lagerort">
          <?php if ($z['kiste_nr'] !== ''): ?>
            <span class="platz-kiste"><?= h($z['kiste_nr']) ?></span>
            <?php if ($z['kiste_neu']): ?><span class="notiz">neu: <?= h(implode(' / ', $z['kiste_neu'])) ?></span><?php endif; ?>
          <?php elseif ($z['standort'] !== ''): ?>
            <span class="platz-pfad"><?= h($z['standort']) ?></span>
          <?php else: ?><span class="platz-leer">kein Lagerort</span><?php endif; ?>
        </td>
        <td data-label="Kategorie"><?= $z['kategorie'] !== '' ? '<span class="kat">' . h($z['kategorie']) . ($z['kategorie_neu'] ? ' (neu)' : '') . '</span>' : '' ?></td>
        <td class="zahl" data-label="Menge"><?= $z['menge'] ?? '?' ?></td>
        <td data-label="Fällig"><?= $z['faellig_am'] ? h(datum_de($z['faellig_am'])) . ' ' . h(faellig_label($z['faellig_typ'])) : '' ?></td>
        <td data-label="Status">
          <?php if ($z['probleme']): ?>
            <span class="marke marke--rot"><?= h(implode('; ', $z['probleme'])) ?></span>
          <?php elseif ($z['artikel_id'] !== null): ?>
            <span class="marke marke--amber">aktualisieren</span>
          <?php else: ?>
            <span class="marke marke--gruen">anlegen</span>
          <?php endif; ?>
          <?php if (!empty($z['hinweis'])): ?><span class="notiz"><?= h($z['hinweis']) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($vorschau['zeilen']) > 200): ?>
    <p class="hinweis">Nur die ersten 200 Zeilen werden angezeigt, importiert werden alle.</p>
  <?php endif; ?>

  <?php if (!$vorschau['fehler']): ?>
    <form method="post" action="daten.php" class="speicherleiste">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="csv_import">
      <input type="hidden" name="token" value="<?= h($vorschau['token']) ?>">
      <button class="btn btn--primaer" type="submit">Jetzt importieren</button>
      <a class="btn" href="daten.php">Abbrechen</a>
      <span class="hinweis">Alles oder nichts: bricht eine Zeile ab, wird nichts geschrieben.</span>
    </form>
  <?php else: ?>
    <p class="flash flash--fehler">Bitte die markierten Zeilen in der Datei korrigieren und erneut hochladen.</p>
  <?php endif; ?>

<?php else: ?>

<div class="kacheln">

  <section class="kachel">
    <h2>Gesamtexport</h2>
    <p>Alle Bestandsdaten in einer JSON-Datei – Kategorien, Kisten, Artikel, Entnahmen, Inventuren.
       Zum Umziehen auf einen anderen Server oder als Sicherung, die auch ohne Datenbankzugriff lesbar ist.</p>
    <p class="hinweis">
      <?php foreach ($anz as $t => $n): ?><?= $n ?> <?= h($t) ?><?= $t !== 'inventur_position' ? ' · ' : '' ?><?php endforeach; ?>
    </p>
    <div class="kachel-knoepfe">
      <a class="btn btn--primaer" href="daten.php?aktion=json_export">JSON herunterladen</a>
    </div>
  </section>

  <?php if (darf('bearbeiter')): ?>
  <section class="kachel">
    <h2>Artikel aus CSV importieren</h2>
    <p>Massenerfassung aus einer Tabellenkalkulation. Erst Vorschau, dann Import.
       Vorhandene Inventarnummern werden aktualisiert, unbekannte Kisten angelegt, wenn Lager/Regal/Fach angegeben sind.</p>
    <form method="post" action="daten.php" enctype="multipart/form-data" class="kachel-knoepfe" style="flex-direction:column;align-items:stretch">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="csv_pruefen">
      <input type="file" name="datei" accept=".csv,text/csv" required>
      <button class="btn btn--primaer" type="submit">Hochladen und prüfen</button>
    </form>
    <details style="margin-top:12px">
      <summary class="hinweis" style="cursor:pointer">Welche Spalten werden erkannt?</summary>
      <p class="hinweis" style="margin-top:8px">
        Pflicht: <strong>Artikel</strong>. Optional: Nummer, Kiste, Lager, Regal, Fach, Standort, Kategorie, Menge,
        Fällig am (TT.MM.JJJJ), Art (Ablauf/Prüfung), Notiz. Groß-/Kleinschreibung egal, Reihenfolge egal.
        Trennzeichen Semikolon oder Komma. Die CSV-Exporte dieser Anwendung lassen sich direkt wieder einlesen.
      </p>
    </details>
  </section>
  <?php endif; ?>

  <?php if (darf('admin')): ?>
  <section class="kachel" style="border-left:3px solid var(--rot)">
    <h2>Gesamtimport</h2>
    <p><strong>Ersetzt alle Bestandsdaten</strong> durch den Inhalt einer JSON-Exportdatei.
       Benutzerkonten bleiben erhalten. Vorher unbedingt <code>./backup.sh</code> laufen lassen.</p>
    <form method="post" action="daten.php" enctype="multipart/form-data" class="kachel-knoepfe" style="flex-direction:column;align-items:stretch"
          onsubmit="return confirm('Wirklich ALLE Bestandsdaten ersetzen? Das lässt sich nur über ein Backup rückgängig machen.');">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="json_import">
      <input type="file" name="datei" accept=".json,application/json" required>
      <input type="text" name="bestaetigung" placeholder="Zur Bestätigung ERSETZEN eingeben" autocomplete="off" required>
      <button class="btn btn--gefahr" type="submit">Alle Daten ersetzen</button>
    </form>
  </section>
  <?php endif; ?>

</div>

<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
