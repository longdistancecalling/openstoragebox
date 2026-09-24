<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

$ich    = benutzer();
$fehler = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $alt = (string) ($_POST['alt']  ?? '');
    $pw  = (string) ($_POST['neu']  ?? '');
    $pw2 = (string) ($_POST['neu2'] ?? '');

    $stmt = $pdo->prepare('SELECT passwort_hash FROM benutzer WHERE benutzer_id = ?');
    $stmt->execute([$ich['benutzer_id']]);
    $hash = (string) $stmt->fetchColumn();

    // Das alte Passwort wird abgefragt, damit eine offen gelassene
    // Sitzung nicht reicht, um das Konto zu übernehmen.
    if (!password_verify($alt, $hash)) {
        $fehler = 'Das bisherige Passwort ist falsch.';
    } else {
        $fehler = passwort_pruefen($pw, $pw2);
    }

    if ($fehler === null) {
        $pdo->prepare('UPDATE benutzer SET passwort_hash = ? WHERE benutzer_id = ?')
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $ich['benutzer_id']]);
        session_regenerate_id(true);
        flash('Passwort geändert.');
        redirect('index.php');
    }
}

$titel = 'Mein Konto';
$aktiv = '';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Mein Konto</h1>
  <span class="anzahl"><?= h($ich['benutzername']) ?> · <?= h(ROLLEN_LABEL[$ich['rolle']]) ?></span>
</div>

<?php if ($fehler): ?><p class="flash flash--fehler"><?= h($fehler) ?></p><?php endif; ?>

<form class="form" method="post" action="profil.php">
  <?= csrf_field() ?>
  <div class="zeile">
    <label for="alt">Bisheriges Passwort</label>
    <input type="password" id="alt" name="alt" required autocomplete="current-password" autofocus>
  </div>
  <div class="paar">
    <div class="zeile">
      <label for="neu">Neues Passwort <span class="hinweis-inline">mind. 10 Zeichen</span></label>
      <input type="password" id="neu" name="neu" required autocomplete="new-password">
    </div>
    <div class="zeile">
      <label for="neu2">Wiederholen</label>
      <input type="password" id="neu2" name="neu2" required autocomplete="new-password">
    </div>
  </div>
  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Passwort ändern</button>
    <a class="btn" href="index.php">Abbrechen</a>
  </div>
</form>

<?php require __DIR__ . '/inc/footer.php'; ?>
