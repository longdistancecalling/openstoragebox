<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Klebeetiketten mit QR-Code.
 *
 * Gedruckt wird auf handelsübliche selbstklebende A4-Bögen. Die Raster
 * unten entsprechen verbreiteten Formaten; jedes davon wird von
 * mehreren Herstellern angeboten, man ist also nicht an eine Marke
 * gebunden.
 *
 * Wichtig beim Drucken: Skalierung auf 100 Prozent stellen. Steht dort
 * "an Seite anpassen", verschiebt sich das Raster um ein paar
 * Millimeter und keine Zeile trifft mehr.
 */

/**
 * Etikettenformate.
 *
 * rand_oben / rand_links sind die unbedruckten Ränder des Bogens,
 * abstand_* die Lücken zwischen den Etiketten. Alle Maße in Millimetern.
 */
const ETIKETTEN_FORMATE = [
    'standard' => [
        'name'    => '70 × 37 mm · 24 je Bogen',
        'hinweis' => 'Avery Zweckform 3474 und baugleiche. Randlos gerastert, '
                   . 'daher unempfindlich gegen kleine Druckversätze.',
        'breite' => 70.0, 'hoehe' => 37.0, 'spalten' => 3, 'zeilen' => 8,
        'rand_oben' => 0.0, 'rand_links' => 0.0, 'abstand_x' => 0.0, 'abstand_y' => 0.0,
        'qr' => 20.0, 'schrift' => 8.5,
    ],
    'gross' => [
        'name'    => '105 × 48 mm · 12 je Bogen',
        'hinweis' => 'Avery Zweckform 3425 und baugleiche. Für Geräte und Kisten, '
                   . 'wo der Code aus einem Meter lesbar sein soll.',
        'breite' => 105.0, 'hoehe' => 48.0, 'spalten' => 2, 'zeilen' => 6,
        'rand_oben' => 4.5, 'rand_links' => 0.0, 'abstand_x' => 0.0, 'abstand_y' => 0.0,
        'qr' => 28.0, 'schrift' => 11.0,
    ],
    'klein' => [
        'name'    => '48,5 × 25,4 mm · 40 je Bogen',
        'hinweis' => 'Avery Zweckform 3667 und baugleiche. Für Kleinteile – '
                   . 'der Code bleibt scannbar, der Text wird knapp.',
        'breite' => 48.5, 'hoehe' => 25.4, 'spalten' => 4, 'zeilen' => 10,
        'rand_oben' => 21.5, 'rand_links' => 8.0, 'abstand_x' => 0.0, 'abstand_y' => 0.0,
        'qr' => 15.0, 'schrift' => 6.5,
    ],
];

$formatKey = (string) ($_GET['format'] ?? 'standard');
if (!isset(ETIKETTEN_FORMATE[$formatKey])) {
    $formatKey = 'standard';
}
$F = ETIKETTEN_FORMATE[$formatKey];

/* ===================================================================
   Auswahl, was gedruckt wird
   =================================================================== */
$was  = (string) ($_GET['was'] ?? 'auswahl');   // auswahl | kategorie | alle | kisten
$kat  = (string) ($_GET['kat'] ?? '');
$ids  = array_values(array_filter(
    array_map('intval', (array) ($_GET['id'] ?? [])),
    fn($i) => $i > 0
));
// Wie viele Felder am Anfang freibleiben – für angebrochene Bögen.
$frei = max(0, min($F['spalten'] * $F['zeilen'] - 1, (int) ($_GET['frei'] ?? 0)));

$etiketten = [];

if ($was === 'kisten') {
    $kisten = $pdo->query('SELECT * FROM v_kiste ORDER BY ' . ORDER_LAGERPLATZ)->fetchAll();
    foreach ($kisten as $k) {
        $etiketten[] = [
            'art'   => 'kiste',
            'code'  => (string) $k['kiste_nr'],
            'titel' => (string) $k['kiste_nr'],
            'zeile' => $k['lager'] . ' / ' . $k['regal'] . ' / ' . $k['fach'],
            'klein' => (string) ($k['notiz'] ?? ''),
        ];
    }
} else {
    $sql = 'SELECT * FROM v_artikel';
    $werte = [];
    if ($was === 'kategorie' && $kat !== '' && ctype_digit($kat)) {
        $sql .= ' WHERE kategorie_id = ?';
        $werte[] = (int) $kat;
    } elseif ($was === 'auswahl' && $ids) {
        $sql .= ' WHERE artikel_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $werte = $ids;
    } elseif ($was === 'auswahl') {
        $sql .= ' WHERE 1=0';   // nichts ausgewählt
    }
    $sql .= ' ORDER BY artikelname';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($werte);

    foreach ($stmt->fetchAll() as $a) {
        $etiketten[] = [
            'art'   => 'artikel',
            'code'  => (string) $a['inventar_nr'],
            'titel' => (string) $a['artikelname'],
            'zeile' => (string) ($a['lagerort_text'] ?? 'ohne Lagerort'),
            'klein' => (string) $a['inventar_nr']
                     . ($a['hat_gebinde'] ? ' · ' . (int) $a['ve_inhalt'] . ' ' . $a['ve_einheit']
                                            . ' je ' . $a['gebinde_einheit'] : ''),
        ];
    }
}

$proBogen = $F['spalten'] * $F['zeilen'];
$gesamt   = count($etiketten) + $frei;
$boegen   = (int) ceil(max(1, $gesamt) / $proBogen);

$kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();
$artikelAlle = $pdo->query('SELECT artikel_id, inventar_nr, artikelname, lagerort_text FROM v_artikel ORDER BY artikelname')->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Etiketten – <?= h(ORGANISATION) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<link rel="stylesheet" href="<?= asset('druck.css') ?>">
<style>
  /* Randlos: der Bogen bringt seine Ränder selbst mit. */
  @page { size: A4 portrait; margin: 0; }

  .bogen {
    width: 210mm;
    height: 297mm;
    padding: <?= $F['rand_oben'] ?>mm 0 0 <?= $F['rand_links'] ?>mm;
    box-sizing: border-box;
    display: grid;
    grid-template-columns: repeat(<?= $F['spalten'] ?>, <?= $F['breite'] ?>mm);
    grid-auto-rows: <?= $F['hoehe'] ?>mm;
    column-gap: <?= $F['abstand_x'] ?>mm;
    row-gap: <?= $F['abstand_y'] ?>mm;
  }

  .et {
    box-sizing: border-box;
    display: flex;
    align-items: center;
    gap: 2.5mm;
    padding: 2mm 2.5mm;
    overflow: hidden;
  }

  .et-qr { flex: 0 0 <?= $F['qr'] ?>mm; }
  .et-qr svg { display: block; width: <?= $F['qr'] ?>mm; height: <?= $F['qr'] ?>mm; }

  .et-text { flex: 1 1 auto; min-width: 0; }

  .et-titel {
    font: 600 <?= $F['schrift'] ?>pt/1.15 var(--sans);
    /* Lange Namen dürfen das Etikett nicht sprengen: zwei Zeilen, dann Schluss. */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .et-zeile {
    margin-top: 0.8mm;
    font: 400 <?= max(5.5, $F['schrift'] - 1.5) ?>pt/1.2 var(--mono);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .et-klein {
    margin-top: 0.5mm;
    font: 400 <?= max(5.0, $F['schrift'] - 2.5) ?>pt/1.2 var(--sans);
    color: #555;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .et-org {
    margin-top: 0.6mm;
    font: 600 <?= max(4.5, $F['schrift'] - 3.5) ?>pt/1 var(--sans);
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #777;
  }

  /* Hilfslinien nur am Bildschirm, damit man die Aufteilung sieht. */
  .et { border: 0.2mm dashed #ccc; }
  @media print { .et { border: 0; } .bogen { break-after: page; } .bogen:last-child { break-after: auto; } }
</style>
</head>
<body class="druck druck--etiketten">

<div class="nur-bildschirm steuerung" style="flex-direction:column;align-items:stretch;gap:14px">
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn" href="export.php">Zurück</a>
    <button class="btn btn--primaer" onclick="window.print()">Drucken</button>
    <span class="hinweis">
      <?= count($etiketten) ?> Etiketten auf <?= $boegen ?> Bogen<?= $boegen === 1 ? '' : 'en' ?>.
      <strong>Im Druckdialog Skalierung auf 100 % stellen</strong> – bei „an Seite anpassen“
      trifft kein Etikett mehr.
    </span>
  </div>

  <form method="get" action="code.php" class="filter" style="margin:0">
    <div class="feld">
      <label for="format">Etikettenformat</label>
      <select id="format" name="format" onchange="this.form.submit()">
        <?php foreach (ETIKETTEN_FORMATE as $k => $f): ?>
          <option value="<?= h($k) ?>" <?= $formatKey === $k ? 'selected' : '' ?>><?= h($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="feld">
      <label for="was">Inhalt</label>
      <select id="was" name="was" onchange="this.form.submit()">
        <option value="auswahl"   <?= $was === 'auswahl'   ? 'selected' : '' ?>>einzelne Artikel</option>
        <option value="kategorie" <?= $was === 'kategorie' ? 'selected' : '' ?>>eine Kategorie</option>
        <option value="alle"      <?= $was === 'alle'      ? 'selected' : '' ?>>alle Artikel</option>
        <option value="kisten"    <?= $was === 'kisten'    ? 'selected' : '' ?>>alle Kisten</option>
      </select>
    </div>
    <?php if ($was === 'kategorie'): ?>
      <div class="feld">
        <label for="kat">Kategorie</label>
        <select id="kat" name="kat" onchange="this.form.submit()">
          <option value="">– wählen –</option>
          <?php foreach ($kategorien as $k): ?>
            <option value="<?= (int) $k['kategorie_id'] ?>" <?= $kat === (string) $k['kategorie_id'] ? 'selected' : '' ?>>
              <?= h($k['bezeichnung']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="feld">
      <label for="frei">Felder freilassen</label>
      <input type="number" id="frei" name="frei" min="0" max="<?= $proBogen - 1 ?>" value="<?= $frei ?>"
             style="width:80px" onchange="this.form.submit()">
    </div>
    <button class="btn" type="submit">Übernehmen</button>
  </form>

  <p class="hinweis" style="margin:0">
    <strong><?= h($F['name']) ?></strong> – <?= h($F['hinweis']) ?>
    Für Material, das nass oder schmutzig wird, lohnen wetterfeste Folienetiketten
    im selben Raster; Papier löst sich an einer Getränkekiste im Regen ab.
    „Felder freilassen“ ist für angebrochene Bögen: die schon abgezogenen
    Etiketten werden übersprungen.
  </p>

  <?php if ($was === 'auswahl'): ?>
    <form method="get" action="code.php" class="form" style="max-width:none;margin:0">
      <input type="hidden" name="format" value="<?= h($formatKey) ?>">
      <input type="hidden" name="was" value="auswahl">
      <input type="hidden" name="frei" value="<?= $frei ?>">
      <div class="zeile">
        <label for="auswahl">Artikel auswählen <span class="hinweis-inline">mit Strg mehrere</span></label>
        <select id="auswahl" name="id[]" multiple size="10">
          <?php foreach ($artikelAlle as $a): ?>
            <option value="<?= (int) $a['artikel_id'] ?>" <?= in_array((int) $a['artikel_id'], $ids, true) ? 'selected' : '' ?>>
              <?= h($a['inventar_nr']) ?> — <?= h($a['artikelname']) ?><?= $a['lagerort_text'] ? ' (' . h($a['lagerort_text']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="knoepfe"><button class="btn btn--primaer" type="submit">Etiketten erzeugen</button></div>
    </form>
  <?php endif; ?>
</div>

<?php if (!$etiketten): ?>
  <p class="leer nur-bildschirm">
    Noch nichts ausgewählt. Oben Format und Inhalt wählen, dann erscheinen die Bögen hier.
  </p>
<?php else: ?>
  <?php
  // Freifelder vorn einfügen, danach die Etiketten in Bögen aufteilen.
  $felder = array_merge(array_fill(0, $frei, null), $etiketten);
  foreach (array_chunk($felder, $proBogen) as $bogen): ?>
    <div class="bogen">
      <?php foreach ($bogen as $e): ?>
        <div class="et">
          <?php if ($e === null): ?>
            <!-- bereits abgezogenes Etikett, bleibt leer -->
          <?php else: ?>
            <div class="et-qr"><?= qr_svg($e['art'], $e['code'], 120) ?></div>
            <div class="et-text">
              <div class="et-titel"><?= h($e['titel']) ?></div>
              <div class="et-zeile"><?= h($e['zeile']) ?></div>
              <?php if ($e['klein'] !== ''): ?>
                <div class="et-klein"><?= h($e['klein']) ?></div>
              <?php endif; ?>
              <div class="et-org"><?= h(ORGANISATION) ?></div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
