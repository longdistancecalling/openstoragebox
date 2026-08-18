<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';

/*
 * Kistenetiketten im Format A5.
 *
 * ?id=…   eine bestimmte Kiste
 * ohne id alle Kisten, je eine Seite
 *
 * Der Seitenumbruch passiert über CSS (@page size: A5 und
 * break-after: page). Es wird also kein PDF erzeugt – der Browser
 * druckt direkt oder speichert selbst als PDF. Das spart eine
 * PDF-Bibliothek im Container und sieht in der Praxis genauso aus.
 */

$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;

if ($id !== null) {
    $stmt = $pdo->prepare('SELECT * FROM kiste WHERE kiste_id = ?');
    $stmt->execute([$id]);
    $kisten = array_filter([$stmt->fetch()]);
} else {
    $kisten = $pdo->query('SELECT * FROM kiste ORDER BY ' . ORDER_LAGERPLATZ)->fetchAll();
}

if (!$kisten) {
    flash('Diese Kiste existiert nicht.', 'fehler');
    redirect('kisten.php');
}

// Inhalte aller benötigten Kisten in einer Abfrage holen, statt pro
// Kiste eine eigene abzusetzen (N+1-Problem).
$ids = array_column($kisten, 'kiste_id');
$platzhalter = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare(
    "SELECT kiste_id, inventar_nr, artikelname, menge, faellig_am, faellig_typ
     FROM v_artikel WHERE kiste_id IN ($platzhalter)
     ORDER BY artikelname"
);
$stmt->execute($ids);

$inhalt = [];
foreach ($stmt->fetchAll() as $z) {
    $inhalt[(int) $z['kiste_id']][] = $z;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kistenetiketten</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/druck.css">
<style>
  /* @page gilt immer fürs ganze Dokument und lässt sich nicht über eine
     CSS-Klasse umschalten. Deshalb steht die A5-Regel hier auf der Seite,
     die sie braucht, und überschreibt das A4 aus druck.css.
     Querformat: 210mm breit, 148mm hoch. */
  @page { size: A5 landscape; margin: 8mm 10mm; }
</style>
</head>
<body class="druck druck--etikett">

<p class="nur-bildschirm steuerung">
  <a class="btn" href="<?= $id !== null ? 'kisten.php' : 'export.php' ?>">Zurück</a>
  <button class="btn btn--primaer" onclick="window.print()">Drucken</button>
  <span class="hinweis">
    Im Druckdialog A5 wählen und Ränder auf „Standard" lassen.
    <?= count($kisten) ?> Seite<?= count($kisten) === 1 ? '' : 'n' ?>.
  </span>
</p>

<?php foreach ($kisten as $k):
    $liste = $inhalt[(int) $k['kiste_id']] ?? [];
?>
<article class="etikett">

  <header class="etikett-kopf">
    <div>
      <div class="etikett-org"><?= h(ORGANISATION) ?></div>
      <div class="etikett-nr"><?= h($k['kiste_nr']) ?></div>
    </div>
    <div class="etikett-ort">
      <span class="etikett-ort-label">Lagerort</span>
      <span class="etikett-ort-wert">
        <?= h($k['lager']) ?> <i>/</i> <?= h($k['regal']) ?> <i>/</i> <?= h($k['fach']) ?>
      </span>
    </div>
  </header>

  <?php if ($k['notiz']): ?>
    <p class="etikett-notiz"><?= h($k['notiz']) ?></p>
  <?php endif; ?>

  <h2 class="etikett-titel">Inhalt<span><?= count($liste) ?> Positionen</span></h2>

  <?php if (!$liste): ?>
    <p class="etikett-leer">Diese Kiste ist leer.</p>
  <?php else: ?>
    <table class="etikett-tabelle">
      <!--
        Der Kopf steht bewusst in einem <thead>: Läuft der Inhalt über
        mehrere A5-Seiten, wiederholt der Browser ihn automatisch
        (display: table-header-group). Ohne das weiß niemand, zu welcher
        Kiste Seite 2 gehört.
      -->
      <thead>
        <tr class="etikett-wdh">
          <th colspan="3">
            <span class="wdh-nr"><?= h($k['kiste_nr']) ?></span>
            <span class="wdh-ort"><?= h($k['lager'] . ' / ' . $k['regal'] . ' / ' . $k['fach']) ?></span>
          </th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($liste as $a): ?>
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
      </tbody>
    </table>
  <?php endif; ?>

  <footer class="etikett-fuss">
    <span><?= h(ORGANISATION) ?></span>
    <span>Stand <?= date('d.m.Y') ?> · Änderungen bitte im System nachtragen</span>
  </footer>

</article>
<?php endforeach; ?>

</body>
</html>
