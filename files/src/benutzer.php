<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('admin');

$ich    = benutzer();
$id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$fehler = [];
$daten  = ['benutzername' => '', 'anzeigename' => '', 'rolle' => 'leser', 'aktiv' => '1'];

/* ===================================================================
   POST
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    foreach (['benutzername', 'anzeigename', 'rolle'] as $f) {
        $daten[$f] = trim((string) ($_POST[$f] ?? ''));
    }
    $daten['aktiv'] = isset($_POST['aktiv']) ? '1' : '0';
    $pw  = (string) ($_POST['passwort']  ?? '');
    $pw2 = (string) ($_POST['passwort2'] ?? '');

    if (!preg_match('/^[a-z0-9._-]{3,40}$/i', $daten['benutzername'])) {
        $fehler[] = 'Benutzername: 3 bis 40 Zeichen, nur Buchstaben, Ziffern, Punkt, Minus, Unterstrich.';
    }
    if ($daten['anzeigename'] === '') {
        $fehler[] = 'Der Anzeigename fehlt.';
    }
    if (!isset(ROLLEN[$daten['rolle']])) {
        $fehler[] = 'Unbekannte Rolle.';
    }

    // Ein Admin darf sich nicht selbst aussperren – weder deaktivieren
    // noch herabstufen. Sonst gibt es im schlimmsten Fall keinen Admin mehr.
    $selbst = $id !== null && $id === (int) $ich['benutzer_id'];
    if ($selbst && ($daten['aktiv'] === '0' || $daten['rolle'] !== 'admin')) {
        $fehler[] = 'Du kannst dein eigenes Konto nicht deaktivieren oder herabstufen.';
    }

    if ($aktion === 'anlegen' || $pw !== '' || $pw2 !== '') {
        $pwFehler = passwort_pruefen($pw, $pw2);
        if ($pwFehler !== null) {
            $fehler[] = $pwFehler;
        }
    }

    if (!$fehler) {
        try {
            if ($aktion === 'anlegen') {
                $pdo->prepare(
                    'INSERT INTO benutzer (benutzername, anzeigename, passwort_hash, rolle, aktiv)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $daten['benutzername'], $daten['anzeigename'],
                    password_hash($pw, PASSWORD_DEFAULT), $daten['rolle'], (int) $daten['aktiv'],
                ]);
                flash('Benutzer ' . $daten['benutzername'] . ' angelegt.');
            } elseif ($aktion === 'speichern' && $id !== null) {
                $sql   = 'UPDATE benutzer SET benutzername = ?, anzeigename = ?, rolle = ?, aktiv = ?';
                $werte = [$daten['benutzername'], $daten['anzeigename'], $daten['rolle'], (int) $daten['aktiv']];
                if ($pw !== '') {
                    $sql .= ', passwort_hash = ?, fehlversuche = 0, gesperrt_bis = NULL';
                    $werte[] = password_hash($pw, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE benutzer_id = ?';
                $werte[] = $id;
                $pdo->prepare($sql)->execute($werte);
                flash('Benutzer gespeichert.' . ($pw !== '' ? ' Passwort wurde neu gesetzt.' : ''));
            }
            redirect('benutzer.php');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $fehler[] = 'Der Benutzername „' . $daten['benutzername'] . '“ ist bereits vergeben.';
            } else {
                error_log('Benutzer speichern: ' . $e->getMessage());
                $fehler[] = 'Der Benutzer konnte nicht gespeichert werden.';
            }
        }
    }
}

/* ===================================================================
   Formular anzeigen (neu oder bearbeiten)
   =================================================================== */
if ($id !== null || ($_GET['neu'] ?? '') === '1') {
    if ($id !== null && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM benutzer WHERE benutzer_id = ?');
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) {
            flash('Diesen Benutzer gibt es nicht.', 'fehler');
            redirect('benutzer.php');
        }
        $daten = [
            'benutzername' => $b['benutzername'], 'anzeigename' => $b['anzeigename'],
            'rolle' => $b['rolle'], 'aktiv' => (string) $b['aktiv'],
        ];
    }
    $selbst = $id !== null && $id === (int) $ich['benutzer_id'];

    $titel = $id === null ? 'Benutzer anlegen' : 'Benutzer bearbeiten';
    $aktiv = 'benutzer';
    require __DIR__ . '/inc/header.php';
    ?>
    <div class="kopf"><h1><?= h($titel) ?></h1></div>

    <?php if ($fehler): ?>
      <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <form class="form" method="post" action="benutzer.php<?= $id !== null ? '?id=' . $id : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="<?= $id === null ? 'anlegen' : 'speichern' ?>">

      <div class="paar">
        <div class="zeile">
          <label for="anzeigename">Anzeigename</label>
          <input type="text" id="anzeigename" name="anzeigename" maxlength="80" required
                 value="<?= h($daten['anzeigename']) ?>" autofocus>
        </div>
        <div class="zeile">
          <label for="benutzername">Benutzername</label>
          <input type="text" id="benutzername" name="benutzername" maxlength="40" required
                 autocapitalize="none" value="<?= h($daten['benutzername']) ?>">
        </div>
      </div>

      <div class="paar">
        <div class="zeile">
          <label for="rolle">Rolle</label>
          <select id="rolle" name="rolle" <?= $selbst ? 'disabled' : '' ?>>
            <?php foreach (ROLLEN_LABEL as $wert => $label): ?>
              <option value="<?= h($wert) ?>" <?= $daten['rolle'] === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($selbst): ?><input type="hidden" name="rolle" value="admin"><?php endif; ?>
        </div>
        <div class="zeile" style="justify-content:flex-end">
          <label class="radio">
            <input type="checkbox" name="aktiv" value="1" <?= $daten['aktiv'] === '1' ? 'checked' : '' ?> <?= $selbst ? 'disabled' : '' ?>>
            <span>Konto aktiv</span>
          </label>
          <?php if ($selbst): ?><input type="hidden" name="aktiv" value="1"><?php endif; ?>
        </div>
      </div>

      <p class="hinweis">
        <strong>Leser</strong> sehen alles und dürfen exportieren.
        <strong>Bearbeiter</strong> dürfen zusätzlich anlegen, ändern, buchen und zählen.
        <strong>Administratoren</strong> dürfen zusätzlich löschen, importieren, Inventuren abschließen und Benutzer verwalten.
      </p>

      <div class="paar">
        <div class="zeile">
          <label for="passwort"><?= $id === null ? 'Passwort' : 'Neues Passwort' ?> <span class="hinweis-inline">mind. 10 Zeichen</span></label>
          <input type="password" id="passwort" name="passwort" autocomplete="new-password" <?= $id === null ? 'required' : '' ?>>
        </div>
        <div class="zeile">
          <label for="passwort2">Wiederholen</label>
          <input type="password" id="passwort2" name="passwort2" autocomplete="new-password" <?= $id === null ? 'required' : '' ?>>
        </div>
      </div>
      <?php if ($id !== null): ?>
        <p class="hinweis">Leer lassen, um das Passwort nicht zu ändern. Ein neues Passwort hebt auch eine Sperre nach Fehlversuchen auf.</p>
      <?php endif; ?>

      <div class="knoepfe">
        <button class="btn btn--primaer" type="submit">Speichern</button>
        <a class="btn" href="benutzer.php">Abbrechen</a>
      </div>
    </form>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

/* ===================================================================
   Liste
   =================================================================== */
$liste = $pdo->query(
    'SELECT b.*, (SELECT COUNT(*) FROM entnahme e WHERE e.erfasst_von = b.benutzer_id) AS buchungen
     FROM benutzer b ORDER BY b.aktiv DESC, b.anzeigename'
)->fetchAll();

$titel = 'Benutzer';
$aktiv = 'benutzer';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Benutzer</h1>
  <span class="anzahl"><?= count($liste) ?> Konten</span>
  <span class="rechts"><a class="btn btn--primaer" href="benutzer.php?neu=1">Benutzer anlegen</a></span>
</div>

<table class="tabelle">
  <thead>
    <tr>
      <th>Name</th><th>Benutzername</th><th>Rolle</th><th>Letzter Login</th>
      <th style="text-align:right">Buchungen</th><th>Status</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($liste as $b):
      $gesperrt = $b['gesperrt_bis'] !== null && strtotime($b['gesperrt_bis']) > time();
  ?>
    <tr<?= !$b['aktiv'] ? ' style="opacity:.55"' : '' ?>>
      <td data-label="Name">
        <span class="name"><?= h($b['anzeigename']) ?></span>
        <?php if ((int) $b['benutzer_id'] === (int) $ich['benutzer_id']): ?><span class="notiz">das bist du</span><?php endif; ?>
      </td>
      <td data-label="Benutzername"><span class="invnr"><?= h($b['benutzername']) ?></span></td>
      <td data-label="Rolle"><span class="kat"><?= h(ROLLEN_LABEL[$b['rolle']]) ?></span></td>
      <td data-label="Letzter Login">
        <?= $b['letzter_login'] ? h(date('d.m.Y H:i', strtotime($b['letzter_login']))) : '<span class="notiz">noch nie</span>' ?>
      </td>
      <td class="zahl" data-label="Buchungen"><?= (int) $b['buchungen'] ?></td>
      <td data-label="Status">
        <?php if (!$b['aktiv']): ?>
          <span class="marke marke--rot">deaktiviert</span>
        <?php elseif ($gesperrt): ?>
          <span class="marke marke--amber">gesperrt bis <?= h(date('H:i', strtotime($b['gesperrt_bis']))) ?></span>
        <?php else: ?>
          <span class="marke marke--gruen">aktiv</span>
        <?php endif; ?>
      </td>
      <td class="aktion" data-label="">
        <a class="btn btn--klein" href="benutzer.php?id=<?= (int) $b['benutzer_id'] ?>">Bearbeiten</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p class="hinweis" style="margin-top:14px">
  Benutzer werden nicht gelöscht, sondern deaktiviert. Ihre Buchungen im Entnahmeprotokoll bleiben so nachvollziehbar.
</p>

<?php require __DIR__ . '/inc/footer.php'; ?>
