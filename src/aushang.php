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
if (!in_array($sortierung, ['ort', 'name', 'liste', 'kategorie'], true)) {
    $sortierung = 'ort';
}
$nurBestand = ($_GET['bestand'] ?? '1') === '1';

$sql = 'SELECT * FROM v_artikel';
if ($nurBestand) {
    $sql .= ' WHERE menge > 0';
}
$sql .= ' ORDER BY ' . match ($sortierung) {
    'name', 'liste' => 'artikelname, ' . ORDER_LAGERORT,
    'kategorie'     => 'kategorie IS NULL, kategorie, artikelname',
    default         => ORDER_LAGERORT . ', artikelname',
};

$alle = $pdo->query($sql)->fetchAll();

// Gruppieren – die Überschrift hängt von der gewählten Sortierung ab.
// 'liste' bleibt bewusst ungruppiert: eine durchgehende A-bis-Z-Liste,
// in der man einen Namen sucht und den Lagerort abliest.
$gruppen = [];
if ($sortierung === 'liste') {
    $gruppen[''] = $alle;
} else {
    foreach ($alle as $a) {
        $schluessel = match ($sortierung) {
            'name'      => mb_strtoupper(mb_substr($a['artikelname'], 0, 1)),
            'kategorie' => $a['kategorie'] ?? 'Ohne Kategorie',
            default     => $a['lagerort_text'] ?? 'Ohne Lagerort',
        };
        $gruppen[$schluessel][] = $a;
    }
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
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<link rel="stylesheet" href="<?= asset('druck.css') ?>">
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
      <option value="liste"     <?= $sortierung === 'liste'     ? 'selected' : '' ?>>Artikel A–Z (durchgehend)</option>
      <option value="kategorie" <?= $sortierung === 'kategorie' ? 'selected' : '' ?>>Kategorie</option>
    </select>
    <label class="radio">
      <input type="checkbox" name="bestand" value="1" <?= $nurBestand ? 'checked' : '' ?> onchange="this.form.submit()">
      <span>nur Artikel mit Bestand</span>
    </label>
  </form>
  <span class="hinweis">A4, beidseitig sparsam – bitte im Druckdialog Hintergrundfarben nicht aktivieren.</span>
</div>

<!--
  Derselbe Kopfaufbau wie bei Kistenetikett und Regalschild: Trägername
  als Eyebrow, große Hauptzeile links, Label-Wert-Block rechts, darunter
  die kräftige Trennlinie. Nur das Blattformat bleibt A4 – der komplette
  Bestand auf A5 quer wären vier Blätter statt einem.
-->
<header class="etikett-kopf aushang-kopf">
  <div>
    <div class="etikett-org"><?= h(ORGANISATION) ?></div>
    <div class="etikett-nr">Lagerübersicht</div>
  </div>
  <div class="etikett-ort">
    <span class="etikett-ort-label">Bestand</span>
    <span class="etikett-ort-wert"><?= count($alle) ?> Pos. · <?= (int) $summe ?> Stück</span>
    <span class="aushang-stand">
      Stand <?= date('d.m.Y') ?> · <?= h(match ($sortierung) {
        'name'      => 'nach Anfangsbuchstabe',
        'liste'     => 'alphabetisch nach Artikel',
        'kategorie' => 'nach Kategorie',
        default     => 'nach Lagerort' }) ?>
    </span>
  </div>
</header>

<p class="etikett-notiz aushang-hinweis">
  Ausdruck vom <?= date('d.m.Y') ?> – veraltet mit jeder Buchung.
  <strong>Entnahmen bitte immer im System erfassen.</strong>
  Wer etwas mitnimmt, ohne es einzutragen, sorgt dafür, dass es im Einsatz fehlt.
</p>

<?php if (!$alle): ?>
  <p class="leer">Keine Artikel vorhanden.</p>
<?php else: ?>
<div class="aushang-spalten">
  <?php foreach ($gruppen as $titel => $liste): ?>
    <section class="aushang-gruppe<?= $sortierung === 'liste' ? ' aushang-gruppe--flach' : '' ?>">
      <?php if ((string) $titel !== ''): ?>
        <h2 class="aushang-titel"><?= h((string) $titel) ?><span><?= count($liste) ?></span></h2>
      <?php endif; ?>
      <table>
        <tbody>
        <?php foreach ($liste as $a):
            $tage  = $a['tage_bis_faellig'] === null ? null : (int) $a['tage_bis_faellig'];
            $stufe = faellig_stufe($tage);
        ?>
          <tr>
            <td class="e-menge"><?= (int) $a['menge'] ?>×</td>
            <td class="e-name">
              <?= h($a['artikelname']) ?>
              <?php if ($sortierung !== 'ort'): ?>
                <span class="e-datum"><?= h($a['lagerort_text'] ?? 'ohne Lagerort') ?></span>
              <?php endif; ?>
              <?php if ($stufe === 'faellig'): ?>
                <span class="e-datum e-warn">seit <?= h(datum_de($a['faellig_am'])) ?> überfällig</span>
              <?php elseif ($stufe === 'bald'): ?>
                <span class="e-datum e-warn">fällig <?= h(datum_de($a['faellig_am'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="e-nr"><?= h($a['kiste_nr'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<footer class="etikett-fuss aushang-fuss">
  <span><?= h(ORGANISATION) ?></span>
  <span>Stand <?= date('d.m.Y') ?> · Änderungen bitte im System nachtragen</span>
</footer>

</body>
</html>
