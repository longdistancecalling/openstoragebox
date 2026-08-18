<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';

/* ===================================================================
   POST – Rückgabe einer Leihe
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['aktion'] ?? '') === 'rueckgabe') {
        $eid = ctype_digit((string) ($_POST['entnahme_id'] ?? '')) ? (int) $_POST['entnahme_id'] : 0;

        try {
            // Wieder eine Transaktion: Bestand zurückbuchen und die Leihe
            // schließen gehören zusammen.
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT artikel_id, menge, grund, zurueck_am
                 FROM entnahme WHERE entnahme_id = ? FOR UPDATE'
            );
            $stmt->execute([$eid]);
            $e = $stmt->fetch();

            if (!$e || $e['grund'] !== GRUND_LEIHE) {
                throw new RuntimeException('Diese Buchung ist keine offene Leihe.');
            }
            if ($e['zurueck_am'] !== null) {
                throw new RuntimeException('Diese Leihe wurde bereits zurückgebucht.');
            }

            $pdo->prepare('UPDATE artikel SET menge = menge + ? WHERE artikel_id = ?')
                ->execute([(int) $e['menge'], (int) $e['artikel_id']]);

            $pdo->prepare('UPDATE entnahme SET zurueck_am = CURDATE() WHERE entnahme_id = ?')
                ->execute([$eid]);

            $pdo->commit();
            flash((int) $e['menge'] . ' Stück zurück im Bestand.');
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            flash($ex->getMessage(), 'fehler');
        } catch (PDOException $ex) {
            $pdo->rollBack();
            error_log('Rückgabe: ' . $ex->getMessage());
            flash('Die Rückgabe konnte nicht gebucht werden.', 'fehler');
        }
        redirect('entnahmen.php');
    }
}

/* ===================================================================
   Filter
   =================================================================== */
$grund = (string) ($_GET['grund'] ?? '');
if ($grund !== '' && !isset(ENTNAHME_GRUENDE[$grund])) {
    $grund = '';
}
$von = (string) ($_GET['von'] ?? '');
$bis = (string) ($_GET['bis'] ?? '');

$bedingungen = [];
$werte       = [];

if ($grund !== '') {
    $bedingungen[] = 'e.grund = :grund';
    $werte[':grund'] = $grund;
}
if (DateTimeImmutable::createFromFormat('Y-m-d', $von)) {
    $bedingungen[] = 'e.entnommen_am >= :von';
    $werte[':von'] = $von;
}
if (DateTimeImmutable::createFromFormat('Y-m-d', $bis)) {
    $bedingungen[] = 'e.entnommen_am <= :bis';
    $werte[':bis'] = $bis;
}
$where = $bedingungen ? 'WHERE ' . implode(' AND ', $bedingungen) : '';

$sql = "SELECT e.*, a.artikelname, a.inventar_nr
        FROM entnahme e
        JOIN artikel a ON a.artikel_id = e.artikel_id
        $where
        ORDER BY e.entnommen_am DESC, e.entnahme_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$zeilen = $stmt->fetchAll();

// Offene Leihen immer separat, unabhängig vom Filter – das ist die
// Frage, die im Lager am häufigsten gestellt wird.
$offen = $pdo->query(
    "SELECT e.*, a.artikelname, a.inventar_nr,
            DATEDIFF(CURDATE(), e.entnommen_am) AS tage_weg
       FROM entnahme e
       JOIN artikel a ON a.artikel_id = e.artikel_id
      WHERE e.grund = '" . GRUND_LEIHE . "' AND e.zurueck_am IS NULL
      ORDER BY e.entnommen_am"
)->fetchAll();

$titel = 'Entnahmen';
$aktiv = 'entnahmen';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Entnahmen</h1>
  <span class="anzahl">
    <?= count($zeilen) ?> Buchungen<?= $offen ? ' · ' . count($offen) . ' Leihen offen' : '' ?>
  </span>
  <span class="rechts">
    <a class="btn btn--primaer" href="entnahme.php">Entnahme buchen</a>
  </span>
</div>

<?php if ($offen): ?>
  <h2 class="abschnitt">Offene Leihen</h2>
  <table class="tabelle">
    <thead>
      <tr>
        <th>Seit</th><th>Artikel</th><th>Bei wem</th>
        <th style="text-align:right">Menge</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($offen as $o):
        $lang = (int) $o['tage_weg'] > 60;
    ?>
      <tr<?= $lang ? ' class="stufe-faellig"' : '' ?>>
        <td data-label="Seit">
          <span class="faellig">
            <span class="faellig-datum"><?= h(datum_de($o['entnommen_am'])) ?></span>
            <span class="faellig-typ"><?= (int) $o['tage_weg'] ?> Tage unterwegs</span>
          </span>
        </td>
        <td data-label="Artikel">
          <span class="invnr"><?= h($o['inventar_nr']) ?></span>
          <span class="name" style="margin-left:6px"><?= h($o['artikelname']) ?></span>
          <?php if ($o['bezug']): ?><span class="notiz"><?= h($o['bezug']) ?></span><?php endif; ?>
        </td>
        <td data-label="Bei wem"><?= h($o['person'] ?? '—') ?></td>
        <td class="zahl" data-label="Menge"><?= (int) $o['menge'] ?></td>
        <td class="aktion" data-label="">
          <form method="post" action="entnahmen.php"
                onsubmit="return confirm('Rückgabe buchen? Der Bestand wird wieder erhöht.');">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="rueckgabe">
            <input type="hidden" name="entnahme_id" value="<?= (int) $o['entnahme_id'] ?>">
            <button class="btn btn--klein btn--primaer" type="submit">Zurück gebucht</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2 class="abschnitt">Protokoll</h2>

<form class="filter" method="get" action="entnahmen.php">
  <div class="feld">
    <label for="grund">Grund</label>
    <select id="grund" name="grund">
      <option value="">alle</option>
      <?php foreach (ENTNAHME_GRUENDE as $wert => $label): ?>
        <option value="<?= h($wert) ?>" <?= $grund === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="feld">
    <label for="von">Von</label>
    <input type="date" id="von" name="von" value="<?= h($von) ?>">
  </div>
  <div class="feld">
    <label for="bis">Bis</label>
    <input type="date" id="bis" name="bis" value="<?= h($bis) ?>">
  </div>
  <button class="btn" type="submit">Anzeigen</button>
  <?php if ($grund !== '' || $von !== '' || $bis !== ''): ?>
    <a class="btn" href="entnahmen.php">Zurücksetzen</a>
  <?php endif; ?>
  <a class="btn" href="export.php?was=entnahmen&amp;format=csv<?= $grund !== '' ? '&amp;grund=' . h($grund) : '' ?>">CSV</a>
</form>

<?php if (!$zeilen): ?>
  <p class="leer">Für diesen Filter sind keine Buchungen erfasst.</p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th>Datum</th><th>Artikel</th><th>Grund</th>
      <th style="text-align:right">Menge</th><th>Person / Bezug</th><th>Status</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($zeilen as $z): ?>
    <tr>
      <td data-label="Datum"><span class="faellig-datum"><?= h(datum_de($z['entnommen_am'])) ?></span></td>
      <td data-label="Artikel">
        <span class="invnr"><?= h($z['inventar_nr']) ?></span>
        <span class="name" style="margin-left:6px"><?= h($z['artikelname']) ?></span>
        <?php if ($z['notiz']): ?><span class="notiz"><?= h($z['notiz']) ?></span><?php endif; ?>
      </td>
      <td data-label="Grund"><span class="grund grund--<?= h($z['grund']) ?>"><?= h(grund_label($z['grund'])) ?></span></td>
      <td class="zahl" data-label="Menge"><?= (int) $z['menge'] ?></td>
      <td data-label="Person / Bezug">
        <?= h($z['person'] ?? '') ?>
        <?php if ($z['bezug']): ?><span class="notiz"><?= h($z['bezug']) ?></span><?php endif; ?>
      </td>
      <td data-label="Status">
        <?php if ($z['grund'] === GRUND_LEIHE): ?>
          <?php if ($z['zurueck_am']): ?>
            <span class="marke marke--gruen">zurück <?= h(datum_de($z['zurueck_am'])) ?></span>
          <?php else: ?>
            <span class="marke marke--amber">offen</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="notiz">endgültig</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
