<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('admin');

$id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$fehler = [];

$daten = [
    'kategorie_id' => '', 'bezeichnung' => '', 'typ' => 'text',
    'einheit' => '', 'optionen' => '', 'hinweis' => '',
    'pflicht' => '0', 'reihenfolge' => '10',
];

/* ===================================================================
   POST
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    if ($aktion === 'loeschen' && $id !== null) {
        // Die Werte hängen per ON DELETE CASCADE daran und verschwinden mit.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM artikel_feldwert WHERE feld_id = ?');
        $stmt->execute([$id]);
        $anz = (int) $stmt->fetchColumn();

        $pdo->prepare('DELETE FROM feld WHERE feld_id = ?')->execute([$id]);
        flash("Feld gelöscht." . ($anz > 0 ? " $anz erfasste Werte wurden mit entfernt." : ''));
        redirect('felder.php');
    }

    foreach (array_keys($daten) as $f) {
        $daten[$f] = trim((string) ($_POST[$f] ?? ''));
    }
    $daten['pflicht'] = isset($_POST['pflicht']) ? '1' : '0';

    if ($daten['bezeichnung'] === '') {
        $fehler[] = 'Die Bezeichnung fehlt.';
    }
    if (!in_array($daten['typ'], ['text', 'zahl', 'datum', 'auswahl', 'ja_nein'], true)) {
        $fehler[] = 'Unbekannter Feldtyp.';
    }
    if ($daten['typ'] === 'auswahl' && trim($daten['optionen']) === '') {
        $fehler[] = 'Beim Typ „Auswahl“ müssen Optionen angegeben werden.';
    }
    if ($daten['reihenfolge'] !== '' && !ctype_digit($daten['reihenfolge'])) {
        $fehler[] = 'Die Reihenfolge muss eine Zahl sein.';
    }

    // Doppelte Bezeichnung in derselben Kategorie verhindern. Ein
    // UNIQUE-Index würde hier nicht greifen, weil MariaDB mehrere
    // NULL-Werte in kategorie_id zulässt.
    if (!$fehler) {
        $sql = 'SELECT COUNT(*) FROM feld WHERE bezeichnung = ? AND '
             . ($daten['kategorie_id'] === '' ? 'kategorie_id IS NULL' : 'kategorie_id = ?');
        $werte = [$daten['bezeichnung']];
        if ($daten['kategorie_id'] !== '') {
            $werte[] = (int) $daten['kategorie_id'];
        }
        if ($id !== null) {
            $sql .= ' AND feld_id <> ?';
            $werte[] = $id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($werte);
        if ((int) $stmt->fetchColumn() > 0) {
            $fehler[] = 'In dieser Kategorie gibt es bereits ein Feld „' . $daten['bezeichnung'] . '“.';
        }
    }

    if (!$fehler) {
        $werte = [
            $daten['kategorie_id'] !== '' ? (int) $daten['kategorie_id'] : null,
            $daten['bezeichnung'],
            $daten['typ'],
            $daten['einheit']  !== '' ? $daten['einheit']  : null,
            $daten['optionen'] !== '' ? $daten['optionen'] : null,
            $daten['hinweis']  !== '' ? $daten['hinweis']  : null,
            (int) $daten['pflicht'],
            $daten['reihenfolge'] !== '' ? (int) $daten['reihenfolge'] : 0,
        ];
        try {
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO feld (kategorie_id, bezeichnung, typ, einheit, optionen, hinweis, pflicht, reihenfolge)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute($werte);
                flash('Feld angelegt.');
            } else {
                $pdo->prepare(
                    'UPDATE feld SET kategorie_id=?, bezeichnung=?, typ=?, einheit=?, optionen=?,
                     hinweis=?, pflicht=?, reihenfolge=? WHERE feld_id=?'
                )->execute([...$werte, $id]);
                flash('Feld gespeichert.');
            }
            redirect('felder.php');
        } catch (PDOException $e) {
            error_log('Feld speichern: ' . $e->getMessage());
            $fehler[] = 'Das Feld konnte nicht gespeichert werden.';
        }
    }
}

$kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();

/* ===================================================================
   Formular
   =================================================================== */
if ($id !== null || ($_GET['neu'] ?? '') === '1') {
    if ($id !== null && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM feld WHERE feld_id = ?');
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if (!$f) {
            flash('Dieses Feld gibt es nicht.', 'fehler');
            redirect('felder.php');
        }
        $daten = [
            'kategorie_id' => (string) ($f['kategorie_id'] ?? ''),
            'bezeichnung'  => (string) $f['bezeichnung'],
            'typ'          => (string) $f['typ'],
            'einheit'      => (string) ($f['einheit'] ?? ''),
            'optionen'     => (string) ($f['optionen'] ?? ''),
            'hinweis'      => (string) ($f['hinweis'] ?? ''),
            'pflicht'      => (string) $f['pflicht'],
            'reihenfolge'  => (string) $f['reihenfolge'],
        ];
    }

    $titel = $id === null ? 'Feld anlegen' : 'Feld bearbeiten';
    $aktiv = 'felder';
    require __DIR__ . '/inc/header.php';
    ?>
    <div class="kopf"><h1><?= h($titel) ?></h1></div>

    <?php if ($fehler): ?>
      <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <form class="form" method="post" action="felder.php<?= $id !== null ? '?id=' . $id : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="speichern">

      <div class="paar">
        <div class="zeile" style="flex:2 1 240px">
          <label for="bezeichnung">Bezeichnung</label>
          <input type="text" id="bezeichnung" name="bezeichnung" maxlength="60" required autofocus
                 value="<?= h($daten['bezeichnung']) ?>" placeholder="z. B. Größe, Leistung, Seriennummer">
        </div>
        <div class="zeile">
          <label for="kategorie_id">Gilt für</label>
          <select id="kategorie_id" name="kategorie_id">
            <option value="">alle Artikel</option>
            <?php foreach ($kategorien as $k): ?>
              <option value="<?= (int) $k['kategorie_id'] ?>"
                <?= $daten['kategorie_id'] === (string) $k['kategorie_id'] ? 'selected' : '' ?>>
                nur <?= h($k['bezeichnung']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="hinweis">
        An eine Kategorie gebunden erscheint das Feld nur bei Artikeln dieser Kategorie –
        „Größe“ bei Kleidung, „Leistung“ bei Elektro. „Alle Artikel“ ist für Angaben
        gedacht, die überall passen, etwa Hersteller oder Seriennummer.
      </p>

      <div class="paar">
        <div class="zeile">
          <label for="typ">Typ</label>
          <select id="typ" name="typ">
            <option value="text"    <?= $daten['typ'] === 'text'    ? 'selected' : '' ?>>Text</option>
            <option value="zahl"    <?= $daten['typ'] === 'zahl'    ? 'selected' : '' ?>>Zahl</option>
            <option value="datum"   <?= $daten['typ'] === 'datum'   ? 'selected' : '' ?>>Datum</option>
            <option value="auswahl" <?= $daten['typ'] === 'auswahl' ? 'selected' : '' ?>>Auswahl</option>
            <option value="ja_nein" <?= $daten['typ'] === 'ja_nein' ? 'selected' : '' ?>>Ja / Nein</option>
          </select>
        </div>
        <div class="zeile">
          <label for="einheit">Einheit</label>
          <input type="text" id="einheit" name="einheit" maxlength="20"
                 value="<?= h($daten['einheit']) ?>" placeholder="W, cm, kg">
        </div>
        <div class="zeile">
          <label for="reihenfolge">Reihenfolge</label>
          <input type="number" id="reihenfolge" name="reihenfolge" min="0" step="10"
                 value="<?= h($daten['reihenfolge']) ?>">
        </div>
      </div>

      <div class="zeile" id="feld-optionen">
        <label for="optionen">Auswahlmöglichkeiten</label>
        <input type="text" id="optionen" name="optionen" maxlength="500"
               value="<?= h($daten['optionen']) ?>" placeholder="S|M|L|XL">
        <p class="hinweis">Mit senkrechtem Strich trennen. Beispiel: <code>S|M|L|XL|XXL</code></p>
      </div>

      <div class="zeile">
        <label for="hinweis">Hinweis im Formular</label>
        <input type="text" id="hinweis" name="hinweis" maxlength="160"
               value="<?= h($daten['hinweis']) ?>" placeholder="optional, erscheint unter dem Feld">
      </div>

      <label class="radio" style="margin-bottom:16px">
        <input type="checkbox" name="pflicht" value="1" <?= $daten['pflicht'] === '1' ? 'checked' : '' ?>>
        <span>Pflichtfeld – Artikel dieser Kategorie lassen sich ohne Wert nicht speichern</span>
      </label>

      <div class="knoepfe">
        <button class="btn btn--primaer" type="submit">Speichern</button>
        <a class="btn" href="felder.php">Abbrechen</a>
      </div>
    </form>

    <?php if ($id !== null): ?>
      <?php
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM artikel_feldwert WHERE feld_id = ?');
        $stmt->execute([$id]);
        $anzWerte = (int) $stmt->fetchColumn();
      ?>
      <form class="form" method="post" action="felder.php?id=<?= $id ?>" style="margin-top:16px"
            onsubmit="return confirm('Feld löschen? Alle erfassten Werte gehen dabei verloren.');">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="loeschen">
        <button class="btn btn--gefahr" type="submit">Feld löschen</button>
        <?php if ($anzWerte > 0): ?>
          <p class="hinweis" style="margin:8px 0 0">
            Bei <?= $anzWerte ?> Artikeln ist ein Wert erfasst. Der geht beim Löschen mit verloren.
          </p>
        <?php endif; ?>
      </form>
    <?php endif; ?>

    <script>
    // Optionsfeld nur beim Typ "Auswahl" zeigen.
    (function () {
      const typ = document.getElementById('typ');
      const opt = document.getElementById('feld-optionen');
      const zeigen = () => { opt.hidden = typ.value !== 'auswahl'; };
      typ.addEventListener('change', zeigen); zeigen();
    })();
    </script>

    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

/* ===================================================================
   Liste
   =================================================================== */
$felder = $pdo->query(
    'SELECT f.*, k.bezeichnung AS kategorie,
            (SELECT COUNT(*) FROM artikel_feldwert w WHERE w.feld_id = f.feld_id) AS befuellt
     FROM feld f
     LEFT JOIN kategorie k ON k.kategorie_id = f.kategorie_id
     ORDER BY f.kategorie_id IS NOT NULL, k.bezeichnung, f.reihenfolge, f.bezeichnung'
)->fetchAll();

const TYP_LABEL = [
    'text' => 'Text', 'zahl' => 'Zahl', 'datum' => 'Datum',
    'auswahl' => 'Auswahl', 'ja_nein' => 'Ja / Nein',
];

$titel = 'Felder';
$aktiv = 'felder';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Benutzerdefinierte Felder</h1>
  <span class="anzahl"><?= count($felder) ?> Felder</span>
  <span class="rechts"><a class="btn btn--primaer" href="felder.php?neu=1">Feld anlegen</a></span>
</div>

<?php if (!$felder): ?>
  <p class="leer">
    Noch keine Zusatzfelder angelegt. Damit lassen sich Angaben erfassen, die nur für
    bestimmte Artikel Sinn ergeben – Größe bei Kleidung, Leistung bei Elektrogeräten.
    <a href="felder.php?neu=1">Erstes Feld anlegen</a>
  </p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th>Feld</th><th>Gilt für</th><th>Typ</th>
      <th style="text-align:right">Reihenfolge</th>
      <th style="text-align:right">Befüllt</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($felder as $f): ?>
    <tr>
      <td data-label="Feld">
        <span class="name"><?= h($f['bezeichnung']) ?></span>
        <?php if ($f['pflicht']): ?><span class="marke marke--amber">Pflicht</span><?php endif; ?>
        <?php if ($f['hinweis']): ?><span class="notiz"><?= h($f['hinweis']) ?></span><?php endif; ?>
      </td>
      <td data-label="Gilt für">
        <?php if ($f['kategorie']): ?>
          <span class="kat"><?= h($f['kategorie']) ?></span>
        <?php else: ?>
          <span class="kat kat--leer">alle Artikel</span>
        <?php endif; ?>
      </td>
      <td data-label="Typ">
        <?= h(TYP_LABEL[$f['typ']] ?? $f['typ']) ?><?php
        if ($f['einheit']) { echo ' <span class="ean">' . h($f['einheit']) . '</span>'; }
        if ($f['typ'] === 'auswahl' && $f['optionen']): ?>
          <span class="notiz"><?= h(str_replace('|', ' · ', $f['optionen'])) ?></span>
        <?php endif; ?>
      </td>
      <td class="zahl" data-label="Reihenfolge"><?= (int) $f['reihenfolge'] ?></td>
      <td class="zahl" data-label="Befüllt"><?= (int) $f['befuellt'] ?></td>
      <td class="aktion" data-label="">
        <a class="btn btn--klein" href="felder.php?id=<?= (int) $f['feld_id'] ?>">Bearbeiten</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p class="hinweis" style="margin-top:14px">
  Die Reihenfolge steuert, in welcher Abfolge die Felder im Artikelformular erscheinen –
  kleinere Zahl zuerst. In Zehnerschritten zu nummerieren spart späteres Umsortieren.
</p>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
