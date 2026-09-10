<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Entnahmeschein.
 *
 * Bei einer Leihe ist der Schein der eigentliche Zweck: er hält fest,
 * wer wann was mitgenommen hat, und wird unterschrieben. Deshalb trägt
 * er bei Leihen zwei Unterschriftsblöcke – Übergabe und Rückgabe –,
 * bei endgültigen Entnahmen nur eine Empfangsbestätigung.
 *
 *   ?id=…      eine bestimmte Buchung
 *   ?offen=1   alle offenen Leihen, je ein Blatt
 *
 * Format A5 quer wie Kistenetikett und Regalschild.
 */

$id    = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$offen = ($_GET['offen'] ?? '') === '1';

$sql = 'SELECT e.*, a.inventar_nr, a.ean, a.artikelname,
               v.lagerort_text, v.kiste_nr,
               kat.bezeichnung AS kategorie,
               b.anzeigename AS gebucht_von,
               DATEDIFF(CURDATE(), e.entnommen_am) AS tage_weg
        FROM entnahme e
        JOIN artikel a    ON a.artikel_id = e.artikel_id
        JOIN v_artikel v  ON v.artikel_id = e.artikel_id
        LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id
        LEFT JOIN benutzer  b   ON b.benutzer_id    = e.erfasst_von';

if ($id !== null) {
    $sql  .= ' WHERE e.entnahme_id = ?';
    $werte = [$id];
} elseif ($offen) {
    $sql  .= " WHERE e.grund = '" . GRUND_LEIHE . "' AND e.zurueck_am IS NULL";
    $werte = [];
} else {
    // Ohne Auswahl: die letzten Buchungen des heutigen Tages – das ist
    // der Fall "gerade gebucht, jetzt Schein drucken".
    $sql  .= ' WHERE e.entnommen_am = CURDATE()';
    $werte = [];
}
$sql .= ' ORDER BY e.entnommen_am DESC, e.entnahme_id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$scheine = $stmt->fetchAll();

if (!$scheine) {
    flash('Für diese Auswahl gibt es keine Buchung.', 'fehler');
    redirect('entnahmen.php');
}

/** Scheinnummer aus der Buchungs-ID – fortlaufend und eindeutig. */
function schein_nr(int $entnahmeId): string
{
    return 'E-' . str_pad((string) $entnahmeId, 5, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entnahmeschein – <?= h(ORGANISATION) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/druck.css">
<style>
  @page { size: A5 landscape; margin: 8mm 10mm; }
</style>
</head>
<body class="druck druck--etikett">

<div class="nur-bildschirm steuerung">
  <a class="btn" href="entnahmen.php">Zurück</a>
  <button class="btn btn--primaer" onclick="window.print()">Drucken</button>
  <a class="btn" href="schein.php?offen=1">Alle offenen Leihen</a>
  <span class="hinweis">
    A5 quer · <?= count($scheine) ?> Schein<?= count($scheine) === 1 ? '' : 'e' ?>.
    Bei Leihen zweifach drucken: einer bleibt im Lager, einer geht mit.
  </span>
</div>

<?php foreach ($scheine as $s):
    $leihe  = $s['grund'] === GRUND_LEIHE;
    $zurueck = $s['zurueck_am'] !== null;
?>
<article class="etikett schein">

  <header class="etikett-kopf">
    <div>
      <div class="etikett-org"><?= h(ORGANISATION) ?></div>
      <div class="etikett-nr"><?= $leihe ? 'Leihschein' : 'Entnahmeschein' ?></div>
    </div>
    <div class="etikett-ort">
      <span class="etikett-ort-label">Beleg</span>
      <span class="etikett-ort-wert"><?= h(schein_nr((int) $s['entnahme_id'])) ?></span>
      <span class="aushang-stand"><?= h(datum_de($s['entnommen_am'])) ?></span>
    </div>
  </header>

  <table class="etikett-tabelle schein-artikel">
    <thead>
      <tr class="etikett-wdh">
        <th colspan="3">
          <span class="wdh-nr"><?= h(schein_nr((int) $s['entnahme_id'])) ?></span>
          <span class="wdh-ort"><?= $leihe ? 'Leihe' : h(grund_label($s['grund'])) ?></span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="e-menge"><?= (int) $s['menge'] ?>×</td>
        <td class="e-name">
          <strong><?= h($s['artikelname']) ?></strong>
          <span class="e-datum">
            <?= h($s['lagerort_text'] ?? 'ohne Lagerort') ?><?php
            if ($s['kategorie']) { echo ' · ' . h($s['kategorie']); } ?>
          </span>
        </td>
        <td class="e-nr"><?= h($s['inventar_nr']) ?></td>
      </tr>
    </tbody>
  </table>

  <dl class="schein-daten">
    <div><dt><?= $leihe ? 'Entleiher' : 'Empfänger' ?></dt>
         <dd><?= h($s['person'] ?: '—') ?></dd></div>
    <div><dt>Grund</dt>
         <dd><?= h(grund_label($s['grund'])) ?></dd></div>
    <div><dt><?= $leihe ? 'Verwendung' : 'Bezug' ?></dt>
         <dd><?= h($s['bezug'] ?: '—') ?></dd></div>
    <div><dt>Ausgegeben von</dt>
         <dd><?= h($s['gebucht_von'] ?: '—') ?></dd></div>
  </dl>

  <?php if ($s['notiz']): ?>
    <p class="schein-notiz"><?= h($s['notiz']) ?></p>
  <?php endif; ?>

  <?php if ($leihe): ?>
    <p class="schein-text">
      Das Material bleibt Eigentum von <?= h(ORGANISATION) ?>. Der Entleiher bestätigt
      den Empfang in ordnungsgemäßem Zustand und haftet für Verlust oder Beschädigung.
      Die Rückgabe erfolgt vollständig und gereinigt.
    </p>

    <div class="schein-unterschriften">
      <div class="us-block">
        <div class="us-titel">Übergabe</div>
        <div class="us-zeile">
          <span class="us-linie"></span>
          <span class="us-label">Datum</span>
        </div>
        <div class="us-zeile">
          <span class="us-linie"></span>
          <span class="us-label">Unterschrift Entleiher<?= $s['person'] ? ' – ' . h($s['person']) : '' ?></span>
        </div>
        <div class="us-zeile">
          <span class="us-linie"></span>
          <span class="us-label">Unterschrift Ausgabe</span>
        </div>
      </div>

      <div class="us-block">
        <div class="us-titel">
          Rückgabe
          <?php if ($zurueck): ?>
            <span class="us-erledigt">gebucht am <?= h(datum_de($s['zurueck_am'])) ?></span>
          <?php endif; ?>
        </div>
        <div class="us-zeile">
          <span class="us-linie"><?= $zurueck ? h(datum_de($s['zurueck_am'])) : '' ?></span>
          <span class="us-label">Datum</span>
        </div>
        <div class="us-zeile">
          <span class="us-linie"></span>
          <span class="us-label">Unterschrift Entleiher</span>
        </div>
        <div class="us-zeile">
          <span class="us-linie"></span>
          <span class="us-label">Unterschrift Rücknahme</span>
        </div>
      </div>
    </div>

  <?php else: ?>
    <p class="schein-text">
      Endgültige Entnahme – eine Rückgabe ist nicht vorgesehen.
    </p>
    <div class="schein-unterschriften">
      <div class="us-block us-block--breit">
        <div class="us-titel">Empfang bestätigt</div>
        <div class="us-zeile">
          <span class="us-linie"><?= h(datum_de($s['entnommen_am'])) ?></span>
          <span class="us-label">Datum</span>
        </div>
        <div class="us-zeile">
          <span class="us-linie"></span>
          <span class="us-label">Unterschrift Empfänger<?= $s['person'] ? ' – ' . h($s['person']) : '' ?></span>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <footer class="etikett-fuss">
    <span><?= h(ORGANISATION) ?> · Beleg <?= h(schein_nr((int) $s['entnahme_id'])) ?></span>
    <span><?= $leihe && !$zurueck
        ? 'Offen seit ' . (int) $s['tage_weg'] . ' Tagen'
        : 'Gedruckt am ' . date('d.m.Y') ?></span>
  </footer>

</article>
<?php endforeach; ?>

</body>
</html>
