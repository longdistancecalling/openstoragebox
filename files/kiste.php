<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';

$id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$fehler = [];

$daten = ['kiste_nr' => '', 'lager' => '', 'regal' => '', 'fach' => '', 'notiz' => ''];

/* ===================================================================
   POST
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    /* ---- Löschen -------------------------------------------------- */
    if ($aktion === 'loeschen' && $id !== null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM artikel WHERE kiste_id = ?');
        $stmt->execute([$id]);
        $belegt = (int) $stmt->fetchColumn();

        if ($belegt > 0) {
            // Die Fremdschlüsselregel ON DELETE RESTRICT würde das ohnehin
            // verhindern. Hier kommt nur die verständliche Erklärung dazu.
            flash("Die Kiste enthält noch $belegt Artikel. Erst leeren oder umlagern, dann löschen.", 'fehler');
            redirect('kiste.php?id=' . $id);
        }

        $pdo->prepare('DELETE FROM kiste WHERE kiste_id = ?')->execute([$id]);
        flash('Kiste gelöscht.');
        redirect('kisten.php');
    }

    /* ---- Speichern ------------------------------------------------ */
    foreach (array_keys($daten) as $feld) {
        $daten[$feld] = trim((string) ($_POST[$feld] ?? ''));
    }

    if ($daten['kiste_nr'] === '') {
        $fehler[] = 'Die Kistennummer fehlt.';
    }
    foreach (['lager' => 'Lager', 'regal' => 'Regal', 'fach' => 'Fach'] as $feld => $label) {
        if ($daten[$feld] === '') {
            $fehler[] = "Die Angabe „$label" . '“ fehlt.';
        }
    }

    if (!$fehler) {
        $werte = [
            $daten['kiste_nr'], $daten['lager'], $daten['regal'], $daten['fach'],
            $daten['notiz'] !== '' ? $daten['notiz'] : null,
        ];

        try {
            if ($id === null) {
                $pdo->prepare('INSERT INTO kiste (kiste_nr, lager, regal, fach, notiz) VALUES (?,?,?,?,?)')
                    ->execute($werte);
                flash('Kiste angelegt.');
            } else {
                $pdo->prepare('UPDATE kiste SET kiste_nr=?, lager=?, regal=?, fach=?, notiz=? WHERE kiste_id=?')
                    ->execute([...$werte, $id]);
                flash('Kiste gespeichert. Alle Artikel darin liegen jetzt am neuen Platz.');
            }
            redirect('kisten.php');
        } catch (PDOException $e) {
            // 1062 = Verstoß gegen einen UNIQUE-Index, hier uq_kiste_nr.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $fehler[] = 'Die Kistennummer „' . $daten['kiste_nr'] . '“ ist bereits vergeben. '
                          . 'Jede Nummer darf im Lager nur einmal vorkommen.';
            } else {
                error_log('Kiste speichern: ' . $e->getMessage());
                $fehler[] = 'Die Kiste konnte nicht gespeichert werden.';
            }
        }
    }
}

/* ===================================================================
   GET
   =================================================================== */
$belegt = 0;
if ($id !== null) {
    $stmt = $pdo->prepare('SELECT * FROM kiste WHERE kiste_id = ?');
    $stmt->execute([$id]);
    $vorhanden = $stmt->fetch();

    if (!$vorhanden) {
        flash('Diese Kiste existiert nicht (mehr).', 'fehler');
        redirect('kisten.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        foreach (array_keys($daten) as $feld) {
            $daten[$feld] = (string) ($vorhanden[$feld] ?? '');
        }
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM artikel WHERE kiste_id = ?');
    $stmt->execute([$id]);
    $belegt = (int) $stmt->fetchColumn();
}

$titel = $id === null ? 'Kiste anlegen' : 'Kiste bearbeiten';
$aktiv = 'kisten';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1><?= h($titel) ?></h1>
  <?php if ($id !== null): ?>
    <span class="anzahl">enthält <?= $belegt ?> Artikel</span>
  <?php endif; ?>
</div>

<?php if ($fehler): ?>
  <ul class="fehlerliste">
    <?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<form class="form" method="post" action="kiste.php<?= $id !== null ? '?id=' . $id : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="aktion" value="speichern">

  <div class="zeile">
    <label for="kiste_nr">Kistennummer</label>
    <input type="text" id="kiste_nr" name="kiste_nr" maxlength="20" required
           value="<?= h($daten['kiste_nr']) ?>" autofocus>
    <p class="hinweis">Systemweit eindeutig. Dieselbe Nummer kann kein zweites Mal vergeben werden.</p>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="lager">Lager</label>
      <input type="text" id="lager" name="lager" maxlength="30" required value="<?= h($daten['lager']) ?>">
    </div>
    <div class="zeile">
      <label for="regal">Regal</label>
      <input type="text" id="regal" name="regal" maxlength="30" required value="<?= h($daten['regal']) ?>">
    </div>
    <div class="zeile">
      <label for="fach">Fach</label>
      <input type="text" id="fach" name="fach" maxlength="30" required value="<?= h($daten['fach']) ?>">
    </div>
  </div>

  <div class="zeile">
    <label for="notiz">Notiz</label>
    <input type="text" id="notiz" name="notiz" maxlength="255" value="<?= h($daten['notiz']) ?>">
  </div>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Speichern</button>
    <a class="btn" href="kisten.php">Abbrechen</a>
  </div>
</form>

<?php if ($id !== null): ?>
  <form class="form" method="post" action="kiste.php?id=<?= $id ?>" style="margin-top:16px"
        onsubmit="return confirm('Diese Kiste wirklich löschen?');">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="loeschen">
    <button class="btn btn--gefahr" type="submit"<?= $belegt > 0 ? ' disabled title="Kiste ist nicht leer"' : '' ?>>
      Kiste löschen
    </button>
    <?php if ($belegt > 0): ?>
      <p class="hinweis" style="margin:8px 0 0">
        Zum Löschen muss die Kiste leer sein.
        <a href="index.php?q=<?= urlencode($daten['kiste_nr']) ?>">Inhalt anzeigen</a>
      </p>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
