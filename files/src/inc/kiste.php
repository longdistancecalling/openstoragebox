<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

$id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$fehler = [];

$daten = [
    'kiste_nr' => '',
    'fach_id'  => (string) ($_GET['v_fach'] ?? ''),
    'notiz'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    if ($aktion === 'loeschen' && $id !== null) {
        erfordere('admin');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM artikel WHERE kiste_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash('Diese Kiste enthält noch Artikel und kann nicht gelöscht werden.', 'fehler');
            redirect('kiste.php?id=' . $id);
        }
        $pdo->prepare('DELETE FROM kiste WHERE kiste_id = ?')->execute([$id]);
        flash('Kiste gelöscht.');
        redirect('kisten.php');
    }

    foreach (array_keys($daten) as $feld) {
        $daten[$feld] = trim((string) ($_POST[$feld] ?? ''));
    }

    if ($daten['kiste_nr'] === '') {
        $fehler[] = 'Die Kistennummer fehlt.';
    }
    if ($daten['fach_id'] === '' || !ctype_digit($daten['fach_id'])) {
        $fehler[] = 'Bitte ein Fach wählen. Fehlt das passende, lege es unter Lagerorte an.';
    }

    if (!$fehler) {
        $fachId = (int) $daten['fach_id'];
        $notiz  = $daten['notiz'] !== '' ? $daten['notiz'] : null;
        try {
            if ($id === null) {
                $pdo->prepare('INSERT INTO kiste (kiste_nr, fach_id, notiz) VALUES (?,?,?)')
                    ->execute([$daten['kiste_nr'], $fachId, $notiz]);
                flash('Kiste angelegt.');
                if (($_POST['weiter'] ?? '') === '1') {
                    redirect('kiste.php?v_fach=' . $fachId);
                }
            } else {
                $pdo->prepare('UPDATE kiste SET kiste_nr=?, fach_id=?, notiz=? WHERE kiste_id=?')
                    ->execute([$daten['kiste_nr'], $fachId, $notiz, $id]);
                flash('Kiste gespeichert. Alle Artikel darin liegen jetzt am neuen Platz.');
            }
            redirect('kisten.php');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $fehler[] = 'Die Kistennummer "' . $daten['kiste_nr'] . '" ist schon vergeben.';
            } else {
                error_log('Kiste speichern: ' . $e->getMessage());
                $fehler[] = 'Die Kiste konnte nicht gespeichert werden.';
            }
        }
    }
}

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
        $daten['kiste_nr'] = (string) $vorhanden['kiste_nr'];
        $daten['fach_id']  = (string) ($vorhanden['fach_id'] ?? '');
        $daten['notiz']    = (string) ($vorhanden['notiz'] ?? '');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM artikel WHERE kiste_id = ?');
    $stmt->execute([$id]);
    $belegt = (int) $stmt->fetchColumn();
}

$faecher = faecher_liste($pdo);

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

<?php if (!$faecher): ?>
  <p class="leer">
    Es gibt noch keine Fächer. Lege zuerst unter
    <a href="orte.php">Lagerorte</a> ein Lager mit Regal und Fach an -
    dann kann eine Kiste dort einsortiert werden.
  </p>
<?php else: ?>

<form class="form" method="post" action="kiste.php<?= $id !== null ? '?id=' . $id : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="aktion" value="speichern">

  <div class="zeile">
    <label for="kiste_nr">Kistennummer</label>
    <input type="text" id="kiste_nr" name="kiste_nr" maxlength="20" required
           value="<?= h($daten['kiste_nr']) ?>" autofocus>
    <p class="hinweis">Systemweit eindeutig. Dieselbe Nummer kann kein zweites Mal vergeben werden.</p>
  </div>

  <div class="zeile">
    <label for="fach_id">Fach</label>
    <?= fach_auswahl($faecher, 'fach_id', $daten['fach_id'], true) ?>
    <p class="hinweis">
      Der Platz der Kiste im Lager. Fehlt ein Fach, lege es unter
      <a href="orte.php">Lagerorte</a> an.
    </p>
  </div>

  <div class="zeile">
    <label for="notiz">Notiz <span class="hinweis-inline">optional</span></label>
    <input type="text" id="notiz" name="notiz" maxlength="255" value="<?= h($daten['notiz']) ?>">
  </div>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit" name="weiter" value="0">Speichern</button>
    <?php if ($id === null): ?>
      <button class="btn" type="submit" name="weiter" value="1"
              title="Speichern und gleich die nächste Kiste anlegen">Speichern &amp; weiter</button>
    <?php endif; ?>
    <a class="btn" href="kisten.php">Abbrechen</a>
  </div>
</form>

<?php if ($id !== null && darf('admin')): ?>
  <form method="post" action="kiste.php?id=<?= $id ?>" class="loesch-form"
        onsubmit="return confirm('Diese Kiste wirklich löschen?');">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="loeschen">
    <button class="btn btn--gefahr" type="submit" <?= $belegt > 0 ? 'disabled' : '' ?>>
      Kiste löschen
    </button>
    <?php if ($belegt > 0): ?>
      <span class="hinweis-inline">Erst die <?= $belegt ?> Artikel umräumen.</span>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
