<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

$fehler = null;

// Gibt es noch keinen Benutzer, wird hier der erste Administrator angelegt.
// Sobald einer existiert, ist dieser Weg geschlossen.
$ersteinrichtung = (int) $pdo->query('SELECT COUNT(*) FROM benutzer')->fetchColumn() === 0;

if (angemeldet()) {
    redirect(startseite());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    /* ---- Ersten Administrator anlegen ------------------------------ */
    if ($aktion === 'einrichten' && $ersteinrichtung) {
        $org   = trim((string) ($_POST['organisation'] ?? ''));
        $name  = trim((string) ($_POST['benutzername'] ?? ''));
        $anz   = trim((string) ($_POST['anzeigename'] ?? ''));
        $pw    = (string) ($_POST['passwort'] ?? '');
        $pw2   = (string) ($_POST['passwort2'] ?? '');

        if ($org === '') {
            $fehler = 'Bitte den Namen der Organisation eingeben.';
        } elseif (mb_strlen($org) > 80) {
            $fehler = 'Der Name der Organisation ist zu lang (höchstens 80 Zeichen).';
        } elseif ($name === '' || !preg_match('/^[a-z0-9._-]{3,40}$/i', $name)) {
            $fehler = 'Benutzername: 3 bis 40 Zeichen, nur Buchstaben, Ziffern, Punkt, Minus, Unterstrich.';
        } elseif ($anz === '') {
            $fehler = 'Bitte einen Anzeigenamen eingeben.';
        } else {
            $fehler = passwort_pruefen($pw, $pw2);
        }

        if ($fehler === null) {
            // Beides in einer Transaktion: entweder die Anwendung ist
            // vollständig eingerichtet oder gar nicht.
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO benutzer (benutzername, anzeigename, passwort_hash, rolle) VALUES (?, ?, ?, "admin")'
            )->execute([$name, $anz, password_hash($pw, PASSWORD_DEFAULT)]);
            $neu = (int) $pdo->lastInsertId();

            // Organisationsname in die Einstellungen. Die Tabelle bringt
            // per Migration einen Startwert mit – hier wird er überschrieben.
            $pdo->prepare(
                'INSERT INTO einstellung (schluessel, wert, geaendert_von) VALUES ("organisation", ?, ?)
                 ON DUPLICATE KEY UPDATE wert = VALUES(wert), geaendert_von = VALUES(geaendert_von)'
            )->execute([$org, $neu]);
            $pdo->commit();

            login($name, $pw);
            flash("Willkommen, $anz. $org ist eingerichtet, du bist der erste Administrator.");
            // Bewusst die Vollansicht, nicht das Terminal: nach der
            // Ersteinrichtung stehen Lagerorte, Kategorien und Benutzer an,
            // und die Begrüßung erscheint nur dort.
            redirect('index.php');
        }
    }

    /* ---- Normale Anmeldung ----------------------------------------- */
    if ($aktion === 'login' && !$ersteinrichtung) {
        $name = trim((string) ($_POST['benutzername'] ?? ''));
        $pw   = (string) ($_POST['passwort'] ?? '');

        $fehler = login($name, $pw);
        if ($fehler === null) {
            // Gemerkt wird die volle REQUEST_URI ("/entnahmen.php?…"); ohne
            // den führenden Schrägstrich passt sie auf die Prüfung unten.
            // Vorher scheiterte jedes gemerkte Ziel daran und es ging
            // immer zur Artikelliste.
            $ziel = ltrim((string) ($_SESSION['nach_login'] ?? ''), '/');
            unset($_SESSION['nach_login']);
            // Nur relative Ziele akzeptieren – kein Redirect auf fremde Hosts.
            // "/" (die Startadresse) bleibt danach leer und führt ebenfalls
            // zur Startseite.
            if (!preg_match('#^[a-z_]+\.php(\?.*)?$#i', $ziel)) {
                $ziel = startseite();
            }
            redirect($ziel);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de"<?= darstellung_attribute() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmelden – <?= h(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<?= darstellung_kopf() ?>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="login-body">

<main class="login">
  <div class="login-kopf">
    <span class="brand-mark">OSB</span>
    <div>
      <div class="login-app"><?= h(APP_NAME) ?></div>
      <?php if (ORGANISATION !== '' && ORGANISATION !== APP_NAME): ?>
        <div class="login-org"><?= h(ORGANISATION) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($fehler): ?>
    <p class="flash flash--fehler"><?= h($fehler) ?></p>
  <?php endif; ?>

  <?php if ($ersteinrichtung): ?>
    <h1>Ersteinrichtung</h1>
    <p class="hinweis">
      Es gibt noch keinen Benutzer. Lege jetzt den ersten Administrator an.
      Dieses Formular erscheint danach nicht mehr.
    </p>
    <form method="post" action="login.php" class="login-form">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="einrichten">
      <div class="zeile">
        <label for="organisation">Organisation</label>
        <input type="text" id="organisation" name="organisation" maxlength="80" required autofocus
               value="<?= h($_POST['organisation'] ?? '') ?>" placeholder="z. B. DRK KV Eisenach">
        <p class="hinweis">Erscheint auf allen Ausdrucken und in der Kopfzeile. Später unter Einstellungen änderbar.</p>
      </div>
      <div class="zeile">
        <label for="anzeigename">Anzeigename</label>
        <input type="text" id="anzeigename" name="anzeigename" maxlength="80" required
               value="<?= h($_POST['anzeigename'] ?? '') ?>" placeholder="Marcel">
      </div>
      <div class="zeile">
        <label for="benutzername">Benutzername</label>
        <input type="text" id="benutzername" name="benutzername" maxlength="40" required
               autocapitalize="none" autocomplete="username"
               value="<?= h($_POST['benutzername'] ?? '') ?>" placeholder="marcel">
      </div>
      <div class="zeile">
        <label for="passwort">Passwort <span class="hinweis-inline">mindestens 10 Zeichen</span></label>
        <input type="password" id="passwort" name="passwort" required autocomplete="new-password">
      </div>
      <div class="zeile">
        <label for="passwort2">Passwort wiederholen</label>
        <input type="password" id="passwort2" name="passwort2" required autocomplete="new-password">
      </div>
      <button class="btn btn--primaer" type="submit">Administrator anlegen</button>
    </form>
  <?php else: ?>
    <h1>Anmelden</h1>
    <form method="post" action="login.php" class="login-form">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="login">
      <div class="zeile">
        <label for="benutzername">Benutzername</label>
        <input type="text" id="benutzername" name="benutzername" maxlength="40" required autofocus
               autocapitalize="none" autocomplete="username"
               value="<?= h($_POST['benutzername'] ?? '') ?>">
      </div>
      <div class="zeile">
        <label for="passwort">Passwort</label>
        <input type="password" id="passwort" name="passwort" required autocomplete="current-password">
      </div>
      <button class="btn btn--primaer" type="submit">Anmelden</button>
    </form>
  <?php endif; ?>

  <!-- Hell/Dunkel schon vor der Anmeldung umschaltbar; alles Weitere
       (Rot-Grün-sicher, Leseschriften) unter Verwaltung → Darstellung. -->
  <div class="login-darstellung"><?php hell_dunkel_schalter(); ?></div>
</main>

</body>
</html>
