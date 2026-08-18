<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';

$id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$fehler = [];

// Vorbelegung für ein neues Formular.
$daten = [
    'artikelname' => '',
    'kiste_id'    => '',
    'kategorie_id'=> '',
    'menge'       => '1',
    'faellig_am'  => '',
    'faellig_typ' => '',
    'notiz'       => '',
];

/* ===================================================================
   POST – speichern oder löschen
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    /* ---- Löschen -------------------------------------------------- */
    if ($aktion === 'loeschen' && $id !== null) {
        $stmt = $pdo->prepare('DELETE FROM artikel WHERE artikel_id = ?');
        $stmt->execute([$id]);
        flash('Artikel gelöscht.');
        redirect('index.php');
    }

    /* ---- Speichern ------------------------------------------------ */
    $daten = [
        'artikelname'  => trim((string) ($_POST['artikelname'] ?? '')),
        'kiste_id'     => trim((string) ($_POST['kiste_id'] ?? '')),
        'kategorie_id' => trim((string) ($_POST['kategorie_id'] ?? '')),
        'menge'        => trim((string) ($_POST['menge'] ?? '1')),
        'faellig_am'   => trim((string) ($_POST['faellig_am'] ?? '')),
        'faellig_typ'  => trim((string) ($_POST['faellig_typ'] ?? '')),
        'notiz'        => trim((string) ($_POST['notiz'] ?? '')),
    ];

    if ($daten['artikelname'] === '') {
        $fehler[] = 'Der Artikelname fehlt.';
    } elseif (mb_strlen($daten['artikelname']) > 120) {
        $fehler[] = 'Der Artikelname ist länger als 120 Zeichen.';
    }

    if ($daten['kiste_id'] === '' || !ctype_digit($daten['kiste_id'])) {
        $fehler[] = 'Bitte eine Kiste auswählen.';
    }

    if (!ctype_digit($daten['menge'])) {
        $fehler[] = 'Die Menge muss eine ganze Zahl ab 0 sein.';
    }

    // Der CHECK in der Datenbank verlangt beides oder nichts – hier
    // wird es abgefangen, bevor eine Exception fliegt.
    if ($daten['faellig_am'] !== '' && $daten['faellig_typ'] === '') {
        $fehler[] = 'Zum Datum fehlt die Angabe, ob es ein Ablauf- oder ein Prüfdatum ist.';
    }
    if ($daten['faellig_typ'] !== '' && $daten['faellig_am'] === '') {
        $fehler[] = 'Zur Art der Fälligkeit fehlt das Datum.';
    }
    if ($daten['faellig_typ'] !== '' && !in_array($daten['faellig_typ'], ['ablauf', 'pruefung'], true)) {
        $fehler[] = 'Unbekannte Art der Fälligkeit.';
    }

    if (!$fehler) {
        $werte = [
            $daten['artikelname'],
            (int) $daten['kiste_id'],
            $daten['kategorie_id'] !== '' ? (int) $daten['kategorie_id'] : null,
            (int) $daten['menge'],
            $daten['faellig_am']  !== '' ? $daten['faellig_am']  : null,
            $daten['faellig_typ'] !== '' ? $daten['faellig_typ'] : null,
            $daten['notiz'] !== '' ? $daten['notiz'] : null,
        ];

        try {
            if ($id === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO artikel
                       (artikelname, kiste_id, kategorie_id, menge, faellig_am, faellig_typ, notiz)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute($werte);
                flash('Artikel angelegt.');
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE artikel SET
                        artikelname = ?, kiste_id = ?, kategorie_id = ?, menge = ?,
                        faellig_am = ?, faellig_typ = ?, notiz = ?
                     WHERE artikel_id = ?'
                );
                $stmt->execute([...$werte, $id]);
                flash('Änderungen gespeichert.');
            }
            redirect('index.php');
        } catch (PDOException $e) {
            error_log('Artikel speichern: ' . $e->getMessage());
            $fehler[] = 'Der Datensatz konnte nicht gespeichert werden.';
        }
    }
}

/* ===================================================================
   GET – bestehenden Datensatz laden
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $id !== null) {
    $stmt = $pdo->prepare('SELECT * FROM artikel WHERE artikel_id = ?');
    $stmt->execute([$id]);
    $vorhanden = $stmt->fetch();

    if (!$vorhanden) {
        flash('Dieser Artikel existiert nicht (mehr).', 'fehler');
        redirect('index.php');
    }

    $daten = [
        'artikelname'  => (string) $vorhanden['artikelname'],
        'kiste_id'     => (string) $vorhanden['kiste_id'],
        'kategorie_id' => (string) ($vorhanden['kategorie_id'] ?? ''),
        'menge'        => (string) $vorhanden['menge'],
        'faellig_am'   => (string) ($vorhanden['faellig_am'] ?? ''),
        'faellig_typ'  => (string) ($vorhanden['faellig_typ'] ?? ''),
        'notiz'        => (string) ($vorhanden['notiz'] ?? ''),
    ];
}

$kisten     = $pdo->query('SELECT kiste_id, kiste_nr, lager, regal, fach FROM kiste ORDER BY ' . ORDER_LAGERPLATZ)->fetchAll();
$kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();

$titel = $id === null ? 'Artikel anlegen' : 'Artikel bearbeiten';
$aktiv = 'index';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1><?= h($titel) ?></h1>
</div>

<?php if (!$kisten): ?>
  <p class="leer">
    Es gibt noch keine Kisten. Ein Artikel braucht einen Lagerplatz.
    <a href="kiste.php">Erste Kiste anlegen</a>
  </p>
<?php else: ?>

<?php if ($fehler): ?>
  <ul class="fehlerliste">
    <?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<form class="form" method="post" action="artikel.php<?= $id !== null ? '?id=' . $id : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="aktion" value="speichern">

  <div class="zeile">
    <label for="artikelname">Artikelname</label>
    <input type="text" id="artikelname" name="artikelname" maxlength="120" required
           value="<?= h($daten['artikelname']) ?>" autofocus>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="kiste_id">Kiste</label>
      <select id="kiste_id" name="kiste_id" required>
        <option value="">– bitte wählen –</option>
        <?php foreach ($kisten as $k): ?>
          <option value="<?= (int) $k['kiste_id'] ?>"
            <?= $daten['kiste_id'] === (string) $k['kiste_id'] ? ' selected' : '' ?>>
            <?= h($k['kiste_nr']) ?> — <?= h($k['lager'] . ' / ' . $k['regal'] . ' / ' . $k['fach']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="zeile">
      <label for="kategorie_id">Kategorie</label>
      <select id="kategorie_id" name="kategorie_id">
        <option value="">– ohne –</option>
        <?php foreach ($kategorien as $k): ?>
          <option value="<?= (int) $k['kategorie_id'] ?>"
            <?= $daten['kategorie_id'] === (string) $k['kategorie_id'] ? ' selected' : '' ?>>
            <?= h($k['bezeichnung']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="zeile">
      <label for="menge">Menge</label>
      <input type="number" id="menge" name="menge" min="0" step="1"
             value="<?= h($daten['menge']) ?>">
    </div>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="faellig_am">Datum</label>
      <input type="date" id="faellig_am" name="faellig_am" value="<?= h($daten['faellig_am']) ?>">
    </div>
    <div class="zeile">
      <label for="faellig_typ">Art des Datums</label>
      <select id="faellig_typ" name="faellig_typ">
        <option value="">– keins –</option>
        <option value="ablauf"   <?= $daten['faellig_typ'] === 'ablauf'   ? 'selected' : '' ?>>Ablaufdatum</option>
        <option value="pruefung" <?= $daten['faellig_typ'] === 'pruefung' ? 'selected' : '' ?>>Nächste Prüfung</option>
      </select>
    </div>
  </div>
  <p class="hinweis">Datum und Art gehören zusammen: entweder beides ausfüllen oder beides leer lassen.</p>

  <div class="zeile">
    <label for="notiz">Notiz</label>
    <input type="text" id="notiz" name="notiz" maxlength="255" value="<?= h($daten['notiz']) ?>">
  </div>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Speichern</button>
    <a class="btn" href="index.php">Abbrechen</a>
  </div>
</form>

<?php if ($id !== null): ?>
  <form class="form" method="post" action="artikel.php?id=<?= $id ?>" style="margin-top:16px"
        onsubmit="return confirm('Diesen Artikel wirklich löschen?');">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="loeschen">
    <button class="btn btn--gefahr" type="submit">Artikel löschen</button>
  </form>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
