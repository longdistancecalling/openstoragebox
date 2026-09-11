<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Regalschild – dasselbe Format wie das Kistenetikett: A5 quer, gleicher
 * Kopfaufbau, gleiche Typografie. Nebeneinander an der Wand sollen die
 * Schilder erkennbar zusammengehören.
 *
 * Der Inhalt steht in einer einzigen Tabelle, obwohl er nach Fach und
 * Kiste gegliedert ist. Grund: nur so wiederholt der Browser den Kopf
 * mit der Regalnummer über <thead> auf jeder Folgeseite. Bei
 * verschachtelten Blöcken ginge das nicht.
 *
 *   ?lager=…&regal=…   ein bestimmtes Regal
 *   ohne Parameter     alle Regale, je eine Seite
 */

$lagerFilter = trim((string) ($_GET['lager'] ?? ''));
$regalFilter = trim((string) ($_GET['regal'] ?? ''));

$regale = $pdo->query(
    'SELECT lager, regal, COUNT(DISTINCT kiste_id) AS kisten
     FROM kiste GROUP BY lager, regal
     ORDER BY lager, LENGTH(regal), regal'
)->fetchAll();

// Alles in einer Abfrage, dann in PHP gruppieren – sonst wären es beim
// Sammeldruck über zwanzig Regale schnell hundert Abfragen (N+1).
$sql = 'SELECT k.kiste_id, k.kiste_nr, k.lager, k.regal, k.fach, k.notiz AS kisten_notiz,
               a.artikel_id, a.inventar_nr, a.artikelname, a.menge,
               a.faellig_am, a.faellig_typ,
               DATEDIFF(a.faellig_am, CURDATE()) AS tage_bis_faellig
        FROM kiste k
        LEFT JOIN artikel a ON a.kiste_id = k.kiste_id';
$werte = [];
$bed   = [];
if ($lagerFilter !== '') { $bed[] = 'k.lager = :lager'; $werte[':lager'] = $lagerFilter; }
if ($regalFilter !== '') { $bed[] = 'k.regal = :regal'; $werte[':regal'] = $regalFilter; }
if ($bed) {
    $sql .= ' WHERE ' . implode(' AND ', $bed);
}
$sql .= ' ORDER BY k.lager, LENGTH(k.regal), k.regal, LENGTH(k.fach), k.fach,
                   LENGTH(k.kiste_nr), k.kiste_nr, a.artikelname';

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);

$baum = [];
foreach ($stmt->fetchAll() as $z) {
    $baum[$z['lager'] . '|' . $z['regal']][$z['fach']][$z['kiste_nr']][] = $z;
}

if (!$baum) {
    flash('Für diese Auswahl gibt es keine Kisten.', 'fehler');
    redirect('export.php');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Regalschilder – <?= h(ORGANISATION) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/druck.css">
<style>
  /* Gleiches Blattformat wie das Kistenetikett. */
  @page { size: A5 landscape; margin: 8mm 10mm; }
</style>
</head>
<body class="druck druck--etikett">

<div class="nur-bildschirm steuerung">
  <a class="btn" href="export.php">Zurück</a>
  <button class="btn btn--primaer" onclick="window.print()">Drucken</button>
  <form method="get" action="regal.php" class="steuerung-form">
    <label for="auswahl">Regal</label>
    <select id="auswahl" name="auswahl" onchange="
        const v = this.value;
        if (!v) { location.href = 'regal.php'; return; }
        const t = v.split('|');
        location.href = 'regal.php?lager=' + encodeURIComponent(t[0]) + '&regal=' + encodeURIComponent(t[1]);">
      <option value="">alle Regale</option>
      <?php foreach ($regale as $r): ?>
        <option value="<?= h($r['lager'] . '|' . $r['regal']) ?>"
          <?= $lagerFilter === $r['lager'] && $regalFilter === $r['regal'] ? 'selected' : '' ?>>
          <?= h($r['lager'] . ' / ' . $r['regal']) ?> (<?= (int) $r['kisten'] ?> Kisten)
        </option>
      <?php endforeach; ?>
    </select>
  </form>
  <span class="hinweis">
    A5 quer wie die Kistenetiketten. <?= count($baum) ?> Regal<?= count($baum) === 1 ? '' : 'e' ?>.
  </span>
</div>

<?php foreach ($baum as $schluessel => $faecher):
    [$lager, $regal] = explode('|', $schluessel, 2);
    $anzKisten = 0; $anzPos = 0; $anzStueck = 0;
    foreach ($faecher as $kisten) {
        foreach ($kisten as $zeilen) {
            $anzKisten++;
            foreach ($zeilen as $z) {
                if ($z['artikel_id'] !== null) { $anzPos++; $anzStueck += (int) $z['menge']; }
            }
        }
    }
?>
<article class="etikett">

  <header class="etikett-kopf">
    <div>
      <div class="etikett-org"><?= h(ORGANISATION) ?></div>
      <div class="etikett-nr"><?= h($regal) ?></div>
    </div>
    <div class="etikett-ort">
      <span class="etikett-ort-label">Lager</span>
      <span class="etikett-ort-wert"><?= h($lager) ?></span>
    </div>
    <div class="etikett-qr">
      <?= qr_svg('regal', $lager . '|' . $regal, 84) ?>
      <span class="qr-label">scannen für Übersicht</span>
    </div>
  </header>

  <h2 class="etikett-titel">
    Regalbelegung
    <span><?= $anzKisten ?> Kisten · <?= $anzPos ?> Positionen · <?= $anzStueck ?> Stück</span>
  </h2>

  <table class="etikett-tabelle">
    <!--
      Wie beim Kistenetikett: der Kopf steht in <thead> und wird per
      display: table-header-group auf jeder Folgeseite wiederholt.
    -->
    <thead>
      <tr class="etikett-wdh">
        <th colspan="3">
          <span class="wdh-nr"><?= h($regal) ?></span>
          <span class="wdh-ort"><?= h($lager) ?></span>
        </th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($faecher as $fach => $kisten): ?>
      <tr class="fachzeile">
        <td colspan="3"><?= h((string) $fach) ?></td>
      </tr>

      <?php foreach ($kisten as $kisteNr => $zeilen):
          $inhalt = array_values(array_filter($zeilen, fn($z) => $z['artikel_id'] !== null));
          $notiz  = $zeilen[0]['kisten_notiz'] ?? null;
      ?>
        <tr class="kistenzeile">
          <td colspan="3">
            <span class="kistenzeile-nr"><?= h((string) $kisteNr) ?></span>
            <?php if ($notiz): ?><span class="kistenzeile-notiz"><?= h($notiz) ?></span><?php endif; ?>
            <span class="kistenzeile-zahl"><?= $inhalt ? count($inhalt) . ' Pos.' : 'leer' ?></span>
          </td>
        </tr>

        <?php foreach ($inhalt as $a): ?>
          <tr>
            <td class="e-menge"><?= (int) $a['menge'] ?>×</td>
            <td class="e-name">
              <?= h($a['artikelname']) ?>
              <?php if ($a['faellig_am']): ?>
                <span class="e-datum"><?= h(faellig_label($a['faellig_typ'])) ?>
                  <?= h(datum_de($a['faellig_am'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="e-nr"><?= h($a['inventar_nr']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

  <footer class="etikett-fuss">
    <span><?= h(ORGANISATION) ?></span>
    <span>Stand <?= date('d.m.Y') ?> · Änderungen bitte im System nachtragen</span>
  </footer>

</article>
<?php endforeach; ?>

</body>
</html>
