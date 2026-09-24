<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

$fehler = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    erfordere('bearbeiter');
    $aktion = (string) ($_POST['aktion'] ?? '');

    if ($aktion === 'anlegen') {
        $bez = trim((string) ($_POST['bezeichnung'] ?? ''));
        if ($bez === '') {
            flash('Bitte eine Bezeichnung eingeben.', 'fehler');
        } else {
            try {
                $pdo->prepare('INSERT INTO kategorie (bezeichnung) VALUES (?)')->execute([$bez]);
                flash('Kategorie angelegt.');
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    flash("Die Kategorie „$bez“ gibt es bereits.", 'fehler');
                } else {
                    error_log('Kategorie anlegen: ' . $e->getMessage());
                    flash('Die Kategorie konnte nicht angelegt werden.', 'fehler');
                }
            }
        }
        redirect('kategorien.php');
    }

    if ($aktion === 'umbenennen') {
        $kid = (int) ($_POST['kategorie_id'] ?? 0);
        $bez = trim((string) ($_POST['bezeichnung'] ?? ''));
        if ($kid > 0 && $bez !== '') {
            try {
                $pdo->prepare('UPDATE kategorie SET bezeichnung = ? WHERE kategorie_id = ?')->execute([$bez, $kid]);
                flash('Kategorie umbenannt.');
            } catch (PDOException $e) {
                flash('Dieser Name ist schon vergeben.', 'fehler');
            }
        }
        redirect('kategorien.php');
    }

    if ($aktion === 'loeschen') {
        erfordere('admin');
        $kid = (int) ($_POST['kategorie_id'] ?? 0);
        // ON DELETE SET NULL: die Artikel bleiben erhalten und stehen
        // danach unter "ohne Kategorie".
        $pdo->prepare('DELETE FROM kategorie WHERE kategorie_id = ?')->execute([$kid]);
        flash('Kategorie gelöscht. Die betroffenen Artikel stehen jetzt ohne Kategorie.');
        redirect('kategorien.php');
    }
}

$kategorien = $pdo->query(
    'SELECT k.kategorie_id, k.bezeichnung, COUNT(a.artikel_id) AS anzahl
     FROM kategorie k
     LEFT JOIN artikel a ON a.kategorie_id = k.kategorie_id
     GROUP BY k.kategorie_id
     ORDER BY k.bezeichnung'
)->fetchAll();

$ohne = (int) $pdo->query('SELECT COUNT(*) FROM artikel WHERE kategorie_id IS NULL')->fetchColumn();

$titel = 'Kategorien';
$aktiv = 'kategorien';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Kategorien</h1>
  <span class="anzahl"><?= count($kategorien) ?> Kategorien · <?= $ohne ?> Artikel ohne</span>
</div>

<?php if (darf('bearbeiter')): ?>
<form class="filter" method="post" action="kategorien.php">
  <?= csrf_field() ?>
  <input type="hidden" name="aktion" value="anlegen">
  <div class="feld feld--breit">
    <label for="bezeichnung">Neue Kategorie</label>
    <input type="text" id="bezeichnung" name="bezeichnung" maxlength="60" required placeholder="z. B. Messtechnik">
  </div>
  <button class="btn btn--primaer" type="submit">Hinzufügen</button>
</form>
<?php endif; ?>

<?php if (!$kategorien): ?>
  <p class="leer">Noch keine Kategorien angelegt.</p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th>Bezeichnung</th>
      <th style="text-align:right">Artikel</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($kategorien as $k): ?>
    <tr>
      <td data-label="Bezeichnung">
        <form method="post" action="kategorien.php" style="display:flex;gap:8px;align-items:center">
          <?= csrf_field() ?>
          <input type="hidden" name="aktion" value="umbenennen">
          <input type="hidden" name="kategorie_id" value="<?= (int) $k['kategorie_id'] ?>">
          <input type="text" name="bezeichnung" maxlength="60" value="<?= h($k['bezeichnung']) ?>"
                 aria-label="Bezeichnung von <?= h($k['bezeichnung']) ?>">
          <button class="btn btn--klein" type="submit" <?= darf('bearbeiter') ? '' : 'disabled' ?>>Umbenennen</button>
        </form>
      </td>
      <td class="zahl" data-label="Artikel">
        <?php if ((int) $k['anzahl'] > 0): ?>
          <a href="index.php?kat=<?= (int) $k['kategorie_id'] ?>"><?= (int) $k['anzahl'] ?></a>
        <?php else: ?>
          <span style="color:var(--muted)">0</span>
        <?php endif; ?>
      </td>
      <td class="aktion" data-label="">
        <form method="post" action="kategorien.php"
              onsubmit="return confirm('Kategorie löschen? Die Artikel bleiben erhalten und stehen danach ohne Kategorie.');">
          <?= csrf_field() ?>
          <input type="hidden" name="aktion" value="loeschen">
          <input type="hidden" name="kategorie_id" value="<?= (int) $k['kategorie_id'] ?>">
          <button class="btn btn--klein btn--gefahr" type="submit" <?= darf('admin') ? '' : 'disabled' ?>>Löschen</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
