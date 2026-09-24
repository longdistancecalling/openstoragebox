<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

// Zeithorizont – ebenfalls über eine Whitelist, damit kein beliebiger
// Wert in die Abfrage gelangt.
$horizonte = [
    '30'  => 'nächste 30 Tage',
    '90'  => 'nächste 90 Tage',
    '365' => 'nächstes Jahr',
    'alle'=> 'alle Termine',
];
$horizont = (string) ($_GET['h'] ?? '90');
if (!isset($horizonte[$horizont])) {
    $horizont = '90';
}

$typ = (string) ($_GET['typ'] ?? '');
if (!in_array($typ, ['ablauf', 'pruefung'], true)) {
    $typ = '';
}

$sql   = 'SELECT * FROM v_artikel WHERE faellig_am IS NOT NULL';
$werte = [];

// Überschrittene Termine sind immer dabei, unabhängig vom Horizont.
if ($horizont !== 'alle') {
    $sql .= ' AND tage_bis_faellig <= :tage';
    $werte[':tage'] = (int) $horizont;
}
if ($typ !== '') {
    $sql .= ' AND faellig_typ = :typ';
    $werte[':typ'] = $typ;
}
$sql .= ' ORDER BY faellig_am ASC, artikelname ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$zeilen = $stmt->fetchAll();

$ueberfaellig = 0;
foreach ($zeilen as $z) {
    if ((int) $z['tage_bis_faellig'] < 0) {
        $ueberfaellig++;
    }
}

$titel = 'Fälligkeiten';
$aktiv = 'faellig';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Fälligkeiten</h1>
  <span class="anzahl">
    <?= count($zeilen) ?> Termine<?= $ueberfaellig > 0 ? ' · ' . $ueberfaellig . ' überschritten' : '' ?>
  </span>
</div>

<form class="filter" method="get" action="faellig.php">
  <div class="feld">
    <label for="h">Zeitraum</label>
    <select id="h" name="h">
      <?php foreach ($horizonte as $wert => $label): ?>
        <option value="<?= h((string) $wert) ?>"<?= $horizont === (string) $wert ? ' selected' : '' ?>>
          <?= h($label) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="feld">
    <label for="typ">Art</label>
    <select id="typ" name="typ">
      <option value="">Ablauf und Prüfung</option>
      <option value="ablauf"   <?= $typ === 'ablauf'   ? 'selected' : '' ?>>nur Ablaufdaten</option>
      <option value="pruefung" <?= $typ === 'pruefung' ? 'selected' : '' ?>>nur Prüftermine</option>
    </select>
  </div>
  <button class="btn" type="submit">Anzeigen</button>
</form>

<?php if (!$zeilen): ?>
  <p class="leer">Im gewählten Zeitraum steht nichts an.</p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th>Termin</th>
      <th>Artikel</th>
      <th>Lagerplatz</th>
      <th>Art</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($zeilen as $z):
      $tage  = (int) $z['tage_bis_faellig'];
      $stufe = faellig_stufe($tage);
  ?>
    <tr class="stufe-<?= h((string) $stufe) ?>">
      <td data-label="Termin">
        <span class="faellig stufe-<?= h((string) $stufe) ?>">
          <span class="faellig-datum"><?= h(datum_de($z['faellig_am'])) ?></span>
          <span class="faellig-typ"><?php
            if ($tage < 0)       { echo 'seit ' . abs($tage) . ' Tagen offen'; }
            elseif ($tage === 0) { echo 'heute'; }
            else                 { echo 'in ' . $tage . ' Tagen'; }
          ?></span>
        </span>
      </td>
      <td data-label="Artikel">
        <span class="name"><?= h($z['artikelname']) ?></span>
        <?php if ($z['notiz']): ?><span class="notiz"><?= h($z['notiz']) ?></span><?php endif; ?>
      </td>
      <td data-label="Lagerplatz"><?= lagerplatz($z) ?></td>
      <td data-label="Art">
        <?php if ($tage < 0): ?>
          <span class="marke marke--rot"><?= h(faellig_label($z['faellig_typ'])) ?></span>
        <?php elseif ($stufe === 'bald'): ?>
          <span class="marke marke--amber"><?= h(faellig_label($z['faellig_typ'])) ?></span>
        <?php else: ?>
          <?= h(faellig_label($z['faellig_typ'])) ?>
        <?php endif; ?>
      </td>
      <td class="aktion" data-label="">
        <a class="btn btn--klein" href="artikel.php?id=<?= (int) $z['artikel_id'] ?>">Bearbeiten</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
