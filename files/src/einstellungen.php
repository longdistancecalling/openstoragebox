<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('admin');

/*
 * Einstellungen.
 *
 * Neue Einstellungen brauchen nur einen Eintrag in der Liste unten –
 * die Tabelle ist ein allgemeines Schlüssel-Wert-Verzeichnis, es ist
 * also keine Migration nötig.
 */

const EINSTELLUNGEN = [
    'organisation' => [
        'label'   => 'Organisation',
        'typ'     => 'text',
        'standard'=> 'Lagerverwaltung',
        'hinweis' => 'Erscheint auf allen Ausdrucken: Etiketten, Regalschilder, '
                   . 'Scheine, Lagerübersicht – und auf der Anmeldeseite.',
        'pflicht' => true,
        'max'     => 80,
    ],
    'basis_url' => [
        'label'   => 'Adresse der Anwendung',
        'typ'     => 'url',
        'standard'=> '',
        'hinweis' => 'Ohne Schrägstrich am Ende, z. B. http://192.168.1.50:8080. '
                   . 'Ist sie gesetzt, enthalten die QR-Codes eine vollständige Adresse – '
                   . 'ein Scan mit der Handykamera öffnet dann direkt die Buchungsseite. '
                   . 'Bleibt das Feld leer, steht nur die Kennung im Code; die Scan-Seite '
                   . 'versteht sie weiterhin, die Kamera-App kann damit aber nichts anfangen.',
        'pflicht' => false,
        'max'     => 200,
    ],
    'warnung_tage' => [
        'label'   => 'Vorwarnzeit für Fälligkeiten',
        'typ'     => 'zahl',
        'standard'=> '30',
        'hinweis' => 'Ab wie vielen Tagen vor Ablauf oder Prüfung ein Artikel als '
                   . '„bald fällig" markiert wird. Bei Prüfterminen, für die ein '
                   . 'Fachbetrieb bestellt werden muss, sind 60 bis 90 Tage oft '
                   . 'realistischer als 30.',
        'pflicht' => true,
        'max'     => 4,
    ],
];

$fehler = [];
$werte  = einstellungen_laden($pdo);

/* ===================================================================
   Speichern
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $neu = [];
    foreach (EINSTELLUNGEN as $key => $def) {
        $wert = trim((string) ($_POST[$key] ?? ''));

        if ($def['pflicht'] && $wert === '') {
            $fehler[] = '„' . $def['label'] . '“ darf nicht leer bleiben.';
            continue;
        }
        if (mb_strlen($wert) > $def['max']) {
            $fehler[] = '„' . $def['label'] . '“ ist zu lang (höchstens ' . $def['max'] . ' Zeichen).';
            continue;
        }
        if ($def['typ'] === 'zahl' && $wert !== '' && (!ctype_digit($wert) || (int) $wert < 1)) {
            $fehler[] = '„' . $def['label'] . '“ muss eine Zahl ab 1 sein.';
            continue;
        }
        if ($def['typ'] === 'url' && $wert !== '') {
            $wert = rtrim($wert, '/');
            if (!preg_match('#^https?://#i', $wert)) {
                $fehler[] = 'Die Adresse muss mit http:// oder https:// beginnen.';
                continue;
            }
            if (!filter_var($wert, FILTER_VALIDATE_URL)) {
                $fehler[] = 'Die Adresse ist keine gültige URL.';
                continue;
            }
        }
        $neu[$key] = $wert;
    }

    if (!$fehler) {
        try {
            $pdo->beginTransaction();
            // INSERT … ON DUPLICATE KEY UPDATE: legt an oder ändert,
            // je nachdem ob der Schlüssel schon existiert.
            $stmt = $pdo->prepare(
                'INSERT INTO einstellung (schluessel, wert, geaendert_von) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE wert = VALUES(wert), geaendert_von = VALUES(geaendert_von)'
            );
            foreach ($neu as $k => $v) {
                $stmt->execute([$k, $v, (int) benutzer()['benutzer_id']]);
            }
            $pdo->commit();
            flash('Einstellungen gespeichert.');
            redirect('einstellungen.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Einstellungen: ' . $e->getMessage());
            $fehler[] = 'Die Einstellungen konnten nicht gespeichert werden.';
        }
    }
    // Bei Fehlern die Eingaben zurückspielen
    foreach (array_keys(EINSTELLUNGEN) as $k) {
        $werte[$k] = (string) ($_POST[$k] ?? '');
    }
}

// Wer hat zuletzt geändert?
$letzte = $pdo->query(
    'SELECT e.schluessel, e.geaendert_am, b.anzeigename
     FROM einstellung e LEFT JOIN benutzer b ON b.benutzer_id = e.geaendert_von
     ORDER BY e.geaendert_am DESC LIMIT 1'
)->fetch();

$titel = 'Einstellungen';
$aktiv = 'einstellungen';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Einstellungen</h1>
  <?php if ($letzte && $letzte['geaendert_am']): ?>
    <span class="anzahl">
      zuletzt geändert <?= h(date('d.m.Y H:i', strtotime($letzte['geaendert_am']))) ?><?php
      if ($letzte['anzeigename']) { echo ' von ' . h($letzte['anzeigename']); } ?>
    </span>
  <?php endif; ?>
</div>

<?php if ($fehler): ?>
  <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<form class="form" method="post" action="einstellungen.php">
  <?= csrf_field() ?>

  <?php foreach (EINSTELLUNGEN as $key => $def):
      $wert = (string) ($werte[$key] ?? $def['standard']);
  ?>
    <div class="zeile">
      <label for="<?= h($key) ?>">
        <?= h($def['label']) ?>
        <?php if (!$def['pflicht']): ?><span class="hinweis-inline">optional</span><?php endif; ?>
      </label>
      <?php if ($def['typ'] === 'zahl'): ?>
        <input type="number" id="<?= h($key) ?>" name="<?= h($key) ?>" min="1" step="1"
               value="<?= h($wert) ?>" style="max-width:140px"
               <?= $def['pflicht'] ? 'required' : '' ?>>
      <?php else: ?>
        <input type="text" id="<?= h($key) ?>" name="<?= h($key) ?>"
               maxlength="<?= (int) $def['max'] ?>" value="<?= h($wert) ?>"
               <?= $def['typ'] === 'url' ? 'inputmode="url" placeholder="http://…"' : '' ?>
               <?= $def['pflicht'] ? 'required' : '' ?>>
      <?php endif; ?>
      <p class="hinweis"><?= h($def['hinweis']) ?></p>
    </div>
  <?php endforeach; ?>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Speichern</button>
    <a class="btn" href="index.php">Abbrechen</a>
  </div>
</form>

<p class="hinweis" style="margin-top:16px">
  Änderungen wirken sofort und für alle Benutzer. Ausdrucke, die vor einer
  Änderung erstellt wurden, tragen weiterhin den alten Namen – das ist richtig
  so, ein Beleg soll zeigen, was zum Zeitpunkt der Ausgabe galt.
</p>

<?php require __DIR__ . '/inc/footer.php'; ?>
