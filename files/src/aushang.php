<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Lagerübersicht zum Aushängen.
 *
 * Gedacht für die Tür oder die Wand neben dem Lager: wer etwas sucht,
 * findet den Lagerort, ohne einen Rechner zu starten. Deshalb nach
 * Lager, Regal und Fach gruppiert – man liest sie im Vorbeigehen und
 * geht dann direkt ans Regal.
 *
 * Zweispaltig, weil eine einspaltige Liste dieselbe Information auf
 * doppelt so viele Blätter verteilt und niemand fünf Seiten an die
 * Tür hängt.
 */

$sortierung = (string) ($_GET['sortierung'] ?? 'ort');
if (!in_array($sortierung, ['ort', 'name', 'kategorie'], true)) {
    $sortierung = 'ort';
}
$nurBestand = ($_GET['bestand'] ?? '1') === '1';

$sql = 'SELECT * FROM v_artikel';
if ($nurBestand) {
    $sql .= ' WHERE menge > 0';
}
$sql .= ' ORDER BY ' . match ($sortierung) {
    'name'      => 'artikelname, ' . ORDER_LAGERORT,
    'kategorie' => 'kategorie IS NULL, kategorie, artikelname',
    default     => ORDER_LAGERORT . ', artikelname',
};

$alle = $pdo->query($sql)->fetchAll();

// Gruppieren – die Überschrift hängt von der gewählten Sortierung ab.
$gruppen = [];
foreach ($alle as $a) {
    $schluessel = match ($sortierung) {
        'name'      => mb_strtoupper(mb_substr($a['artikelname'], 0, 1)),
        'kategorie' => $a['kategorie'] ?? 'Ohne Kategorie',
        default     => $a['lagerort_text'] ?? 'Ohne Lagerort',
    };
    $gruppen[$schluessel][] = $a;
}

$summe = array_sum(array_column($alle, 'menge'));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lagerübersicht – <?= h(ORGANISATION) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/druck.css">
<style>
  @page { size: A4 portrait; margin: 12mm 10mm 14mm; }
</style>
</head>
<body class="druck druck--aushang">

<div class="nur-bildschirm steuerung">
  <a class="btn" href="export.php">Zurück</a>
  <button class="btn btn--primaer" onclick="window.print()">Drucken</button>
  <form method="get" action="aushang.php" class="steuerung-form">
    <label for="sortierung">Gruppiert nach</label>
    <select id="sortierung" name="sortierung" onchange="this.form.submit()">
      <option value="ort"       <?= $sortierung === 'ort'       ? 'selected' : '' ?>>Lagerort</option>
      <option value="name"      <?= $sortierung === 'name'      ? 'selected' : '' ?>>Anfangsbuchstabe</option>
      <option value="kategorie" <?= $sortierung === 'kategorie' ? 'selected' : '' ?>>Kategorie</option>
    </select>
    <label class="radio">
      <input type="checkbox" name="bestand" value="1" <?= $nurBestand ? 'checked' : '' ?> onchange="this.form.submit()">
      <span>nur Artikel mit Bestand</span>
    </label>
  </form>
  <span class="hinweis">A4, beidseitig sparsam – bitte im Druckdialog Hintergrundfarben nicht aktivieren.</span>
</div>

<header class="aushang-kopf">
  <div>
    <div class="aushang-org"><?= h(ORGANISATION) ?></div>
    <h1>Lagerübersicht</h1>
  </div>
  <div class="aushang-meta">
    <div><strong><?= count($alle) ?></strong> Positionen · <strong><?= (int) $summe ?></strong> Stück</div>
    <div>Stand <?= date('d.m.Y') ?></div>
    <div>gruppiert nach <?= h(match ($sortierung) {
        'name' => 'Anfangsbuchstabe', 'kategorie' => 'Kategorie', default => 'Lagerort' }) ?></div>
  </div>
</header>

<p class="aushang-hinweis">
  Diese Liste ist ein Ausdruck vom <?= date('d.m.Y') ?> und veraltet mit jeder Buchung.
  <strong>Entnahmen bitte immer im System erfassen</strong> – wer etwas mitnimmt, ohne es
  einzutragen, sorgt dafür, dass es im Einsatz fehlt.
</p>

<?php if (!$alle): ?>
  <p class="leer">Keine Artikel vorhanden.</p>
<?php else: ?>
<div class="aushang-spalten">
  <?php foreach ($gruppen as $titel => $liste): ?>
    <section class="aushang-gruppe">
      <h2><?= h((string) $titel) ?><span><?= count($liste) ?></span></h2>
      <table>
        <tbody>
        <?php foreach ($liste as $a):
            $tage  = $a['tage_bis_faellig'] === null ? null : (int) $a['tage_bis_faellig'];
            $stufe = faellig_stufe($tage);
        ?>
          <tr>
            <td class="a-menge"><?= (int) $a['menge'] ?>×</td>
            <td class="a-name">
              <?= h($a['artikelname']) ?>
              <?php if ($sortierung !== 'ort'): ?>
                <span class="a-ort"><?= h($a['lagerort_text'] ?? 'ohne Lagerort') ?></span>
              <?php endif; ?>
              <?php if ($stufe === 'faellig'): ?>
                <span class="a-warn">seit <?= h(datum_de($a['faellig_am'])) ?> überfällig</span>
              <?php elseif ($stufe === 'bald'): ?>
                <span class="a-warn">fällig <?= h(datum_de($a['faellig_am'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="a-kiste"><?= h($a['kiste_nr'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<footer class="aushang-fuss">
  <span><?= h(ORGANISATION) ?> · Lagerverwaltung</span>
  <span>Fragen oder Fehler in der Liste: bitte im System korrigieren</span>
</footer>

</body>
</html>
