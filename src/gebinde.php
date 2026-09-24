<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Gebindeübersicht.
 *
 * Beantwortet die Frage, die eine reine Artikelliste nicht beantwortet:
 * Wie viele Kisten stehen da, und wie viele Flaschen sind das
 * zusammen? Gruppiert nach Gebindeart, damit "alle Kisten" und "alle
 * Kartons" jeweils eine Summe bekommen.
 */

$nurBestand = ($_GET['bestand'] ?? '1') === '1';

$sql = 'SELECT * FROM v_artikel WHERE ve_inhalt IS NOT NULL';
if ($nurBestand) {
    $sql .= ' AND gesamt_einzeln > 0';
}
$sql .= ' ORDER BY gebinde_einheit, artikelname';

$artikel = $pdo->query($sql)->fetchAll();

// Nach Gebindeart gruppieren und je Gruppe summieren.
$gruppen = [];
foreach ($artikel as $a) {
    $gruppen[$a['gebinde_einheit'] ?: 'Gebinde'][] = $a;
}

$summeGebinde = array_sum(array_column($artikel, 'menge'));
$summeEinzeln = array_sum(array_column($artikel, 'gesamt_einzeln'));
$summeLose    = array_sum(array_column($artikel, 'lose'));

// Artikel ohne Gebinde nur zählen, für den Hinweis am Ende.
$ohneGebinde = (int) $pdo->query('SELECT COUNT(*) FROM artikel WHERE ve_inhalt IS NULL')->fetchColumn();

$titel = 'Gebinde';
$aktiv = 'index';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Gebindeübersicht</h1>
  <span class="anzahl"><?= count($artikel) ?> Artikel in Gebinden</span>
  <span class="rechts">
    <a class="btn" href="gebinde.php?bestand=<?= $nurBestand ? '0' : '1' ?>">
      <?= $nurBestand ? 'auch leere zeigen' : 'nur mit Bestand' ?>
    </a>
    <a class="btn" href="index.php">Alle Artikel</a>
  </span>
</div>

<?php if (!$artikel): ?>
  <p class="leer">
    Für keinen Artikel ist eine Verpackungseinheit hinterlegt.
    Beim Bearbeiten eines Artikels lässt sich unter „Verpackungseinheit“ eintragen,
    wie viele Einzelstücke in einem Gebinde stecken – etwa 20 Flaschen je Kiste.
  </p>
<?php else: ?>

<div class="kennzahlen">
  <div class="kennzahl">
    <span class="kz-wert"><?= (int) $summeGebinde ?></span>
    <span class="kz-label">volle Gebinde</span>
  </div>
  <div class="kennzahl">
    <span class="kz-wert"><?= (int) $summeEinzeln ?></span>
    <span class="kz-label">Einzelstücke gesamt</span>
  </div>
  <div class="kennzahl<?= $summeLose > 0 ? ' kennzahl--amber' : '' ?>">
    <span class="kz-wert"><?= (int) $summeLose ?></span>
    <span class="kz-label">davon lose</span>
  </div>
  <div class="kennzahl">
    <span class="kz-wert"><?= count($gruppen) ?></span>
    <span class="kz-label">Gebindearten</span>
  </div>
</div>

<?php foreach ($gruppen as $art => $liste):
    $gGebinde = array_sum(array_column($liste, 'menge'));
    $gEinzeln = array_sum(array_column($liste, 'gesamt_einzeln'));
?>
  <h2 class="abschnitt">
    <?= h(mehrzahl((string) $art)) ?>
    — <?= $gGebinde ?> Stück mit <?= $gEinzeln ?> Einzelstücken
  </h2>

  <table class="tabelle">
    <thead>
      <tr>
        <th>Nr.</th><th>Artikel</th><th>Lagerort</th>
        <th style="text-align:right">Gebinde</th>
        <th style="text-align:right">Inhalt je</th>
        <th style="text-align:right">Lose</th>
        <th style="text-align:right">Gesamt</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($liste as $a): ?>
      <tr<?= (int) $a['gesamt_einzeln'] === 0 ? ' style="opacity:.55"' : '' ?>>
        <td data-label="Nr."><span class="invnr"><?= h($a['inventar_nr']) ?></span></td>
        <td data-label="Artikel">
          <span class="name"><?= h($a['artikelname']) ?></span>
          <?php if ($a['notiz']): ?><span class="notiz"><?= h($a['notiz']) ?></span><?php endif; ?>
        </td>
        <td data-label="Lagerort"><?= lagerort($a) ?></td>
        <td class="zahl" data-label="Gebinde"><?= (int) $a['menge'] ?></td>
        <td class="zahl" data-label="Inhalt je">
          <?= (int) $a['ve_inhalt'] ?> <span class="notiz" style="display:inline"><?= h($a['ve_einheit']) ?></span>
        </td>
        <td class="zahl" data-label="Lose">
          <?= (int) $a['lose'] > 0 ? '<span class="marke marke--amber">' . (int) $a['lose'] . '</span>' : '–' ?>
        </td>
        <td class="zahl" data-label="Gesamt"><strong><?= (int) $a['gesamt_einzeln'] ?></strong></td>
        <td class="aktion" data-label="">
          <?php if (darf('bearbeiter')): ?>
            <a class="btn btn--klein" href="zugang.php?artikel=<?= (int) $a['artikel_id'] ?>">Einbuchen</a>
            <a class="btn btn--klein" href="entnahme.php?artikel=<?= (int) $a['artikel_id'] ?>">Entnehmen</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>

<p class="hinweis" style="margin-top:16px">
  „Lose“ sind Einzelstücke aus einem angebrochenen Gebinde. Werden bei einer Entnahme
  mehr Einzelstücke gebraucht als lose vorhanden sind, wird automatisch ein volles
  Gebinde aufgebrochen – so wie im Regal, wo jemand eine Kiste öffnet.
  <?php if ($ohneGebinde > 0): ?>
    <br><?= $ohneGebinde ?> weitere Artikel werden einzeln gezählt und tauchen hier nicht auf.
  <?php endif; ?>
</p>

<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
