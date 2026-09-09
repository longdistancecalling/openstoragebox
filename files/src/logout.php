<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

// Abmelden nur per POST: ein <img src="logout.php"> auf einer fremden
// Seite würde sonst jeden Besucher abmelden (Logout-CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout();
    redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><title>Abmelden</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="login-body"><main class="login">
  <h1>Abmelden?</h1>
  <form method="post" action="logout.php" class="login-form">
    <?= csrf_field() ?>
    <button class="btn btn--primaer" type="submit">Ja, abmelden</button>
    <a class="btn" href="index.php">Zurück</a>
  </form>
</main></body></html>
