<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';

$suche = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT k.*, COUNT(a.artikel_id) AS anzahl_artikel,
               COALESCE(SUM(a.menge), 0) AS summe_menge
        FROM kiste k
        LEFT JOIN artikel a ON a.kiste_id = k.kiste_id';
$werte = [];

if ($suche !== '') {
    // Ein Platzhalter, siehe Kommentar in index.php.
    $sql .= " WHERE CONCAT_WS(' ', k.kiste_nr, k.lager, k.regal, k.fach, IFNULL(k.notiz,'')) LIKE :q";
    $werte[':q'] = '%' . $suche . '%';
}

$sql .= ' GROUP BY k.kiste_id ORDER BY ' . str_replace(
    ['lager', 'regal', 'fach', 'kiste_nr'],
    ['k.lager', 'k.regal', 'k.fach', 'k.kiste_nr'],
    ORDER_LAGERPLATZ
);

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$kisten = $stmt->fetchAll();

$titel = 'Kisten';
$aktiv = 'kisten';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Kisten</h1>
  <span class="anzahl"><?= count($kisten) ?> Kisten</span>
  <span class="rechts">
    <a class="btn btn--primaer" href="kiste.php">Kiste anlegen</a>
  </span>
</div>

<form class="filter" method="get" action="kisten.php">
  <div class="feld feld--breit">
    <label for="q">Suche</label>
    <input type="search" id="q" name="q" value="<?= h($suche) ?>" placeholder="Kistennummer, Lager, Regal oder Fach">
  </div>
  <button class="btn" type="submit">Anzeigen</button>
  <?php if ($suche !== ''): ?><a class="btn" href="kisten.php">Zurücksetzen</a><?php endif; ?>
</form>

<?php if (!$kisten): ?>
  <p class="leer">Keine Kisten vorhanden. <a href="kiste.php">Erste Kiste anlegen</a></p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th>Lagerplatz</th>
      <th>Notiz</th>
      <th style="text-align:right">Artikel</th>
      <th style="text-align:right">Stück gesamt</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($kisten as $k): ?>
    <tr>
      <td data-label="Lagerplatz"><?= lagerplatz($k) ?></td>
      <td data-label="Notiz"><?= h($k['notiz'] ?? '') ?></td>
      <td class="zahl" data-label="Artikel">
        <?php if ((int) $k['anzahl_artikel'] > 0): ?>
          <a href="index.php?q=<?= urlencode((string) $k['kiste_nr']) ?>&amp;sort=name"><?= (int) $k['anzahl_artikel'] ?></a>
        <?php else: ?>
          <span style="color:var(--muted)">0</span>
        <?php endif; ?>
      </td>
      <td class="zahl" data-label="Stück gesamt"><?= (int) $k['summe_menge'] ?></td>
      <td class="aktion" data-label="">
        <a class="btn btn--klein" href="kiste.php?id=<?= (int) $k['kiste_id'] ?>">Bearbeiten</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
