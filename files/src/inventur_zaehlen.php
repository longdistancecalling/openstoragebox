<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
if ($id === null) {
    redirect('inventur.php');
}

$stmt = $pdo->prepare('SELECT * FROM inventur WHERE inventur_id = ?');
$stmt->execute([$id]);
$inv = $stmt->fetch();

if (!$inv) {
    flash('Diese Inventur existiert nicht.', 'fehler');
    redirect('inventur.php');
}

/* ===================================================================
   POST – Zählwerte speichern
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($inv['abgeschlossen_am']) {
        flash('Diese Inventur ist abgeschlossen und lässt sich nicht mehr ändern.', 'fehler');
        redirect('inventur.php?id=' . $id);
    }

    $ist        = $_POST['ist']        ?? [];
    $istLose    = $_POST['ist_lose']   ?? [];
    $bemerkung  = $_POST['bemerkung']  ?? [];
    $gespeichert = 0;

    try {
        $pdo->beginTransaction();

        // Ein vorbereitetes Statement, viele Ausführungen – MariaDB
        // parst die Abfrage nur einmal.
        $upd = $pdo->prepare(
            'UPDATE inventur_position
                SET ist_menge = ?, ist_lose = ?, bemerkung = ?,
                    gezaehlt_am = NOW(), gezaehlt_von = ?
              WHERE inventur_id = ? AND artikel_id = ?'
        );

        foreach ($ist as $artikelId => $wert) {
            if (!ctype_digit((string) $artikelId)) {
                continue;
            }
            $wert = trim((string) $wert);
            if ($wert === '' || !ctype_digit($wert)) {
                continue;   // leer heißt "nicht gezählt", nicht "null Stück"
            }
            // Lose Stücke sind optional: wer nur Gebinde zählt, lässt
            // das zweite Feld leer, dann gilt 0.
            $lose = trim((string) ($istLose[$artikelId] ?? ''));
            $lose = ctype_digit($lose) ? (int) $lose : 0;

            $bem = trim((string) ($bemerkung[$artikelId] ?? ''));
            $upd->execute([
                (int) $wert,
                $lose,
                $bem !== '' ? mb_substr($bem, 0, 255) : null,
                (int) benutzer()['benutzer_id'],
                $id,
                (int) $artikelId,
            ]);
            $gespeichert++;
        }

        $pdo->commit();
        flash("$gespeichert Zählwerte gespeichert.");
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Zählwerte speichern: ' . $e->getMessage());
        flash('Die Zählwerte konnten nicht gespeichert werden.', 'fehler');
    }

    redirect('inventur_zaehlen.php?id=' . $id . '&ort=' . urlencode((string) ($_POST['ort'] ?? '')));
}

/* ===================================================================
   Positionen laden, nach Lagerort gruppiert
   =================================================================== */
$ortFilter = trim((string) ($_GET['ort'] ?? ''));
$nurOffen  = isset($_GET['offen']);

$sql   = 'SELECT * FROM v_inventur_position WHERE inventur_id = :id';
$werte = [':id' => $id];

if ($ortFilter !== '') {
    $sql .= ' AND lagerort = :ort';
    $werte[':ort'] = $ortFilter;
}
if ($nurOffen) {
    $sql .= ' AND ist_menge IS NULL';
}
$sql .= ' ORDER BY lagerort, artikelname';

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$positionen = $stmt->fetchAll();

// Lagerorte für den Filter – die Reihenfolge entspricht dem Rundgang.
$stmt = $pdo->prepare(
    'SELECT lagerort, COUNT(*) AS gesamt, SUM(ist_menge IS NULL) AS offen
     FROM v_inventur_position WHERE inventur_id = ?
     GROUP BY lagerort ORDER BY lagerort'
);
$stmt->execute([$id]);
$orte = $stmt->fetchAll();

/* ===================================================================
   Druckbare Zählliste
   =================================================================== */
if (($_GET['format'] ?? '') === 'druck') {
    $gruppen = [];
    foreach ($positionen as $p) {
        $gruppen[$p['lagerort'] ?? 'ohne Lagerort'][] = $p;
    }
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
      <meta charset="utf-8">
      <title>Zählliste – <?= h($inv['bezeichnung']) ?></title>
      <link rel="stylesheet" href="assets/style.css">
      <link rel="stylesheet" href="assets/druck.css">
    </head>
    <body class="druck druck--liste">
      <div class="druck-kopf">
        <div>
          <div class="druck-org"><?= h(ORGANISATION) ?></div>
          <h1><?= h($inv['bezeichnung']) ?> – Zählliste</h1>
        </div>
        <span>Stand <?= date('d.m.Y') ?> · Gezählt von ______________________</span>
      </div>

      <?php foreach ($gruppen as $ort => $liste): ?>
        <h2 class="druck-gruppe"><?= h((string) $ort) ?></h2>
        <table class="druck-tabelle">
          <thead>
            <tr>
              <th>Nr.</th><th>Artikel</th>
              <th class="r">Soll</th>
              <th class="r zaehlfeld">Ist Gebinde</th>
              <th class="r zaehlfeld">Ist lose</th>
              <th>Bemerkung</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($liste as $p): ?>
            <?php $ve = (int) ($p['ve_inhalt'] ?? 0); ?>
            <tr>
              <td class="mono"><?= h($p['inventar_nr'] ?? '') ?></td>
              <td>
                <?= h($p['artikelname']) ?>
                <?php if ($ve): ?>
                  <span class="z-ve"><?= (int) $ve ?> <?= h($p['ve_einheit'] ?: 'Stück') ?>
                    je <?= h($p['gebinde_einheit'] ?: 'Gebinde') ?></span>
                <?php endif; ?>
              </td>
              <td class="r mono">
                <?= (int) $p['soll_menge'] ?><?php
                if ($ve && (int) $p['soll_lose'] > 0) { echo ' + ' . (int) $p['soll_lose']; } ?>
              </td>
              <td class="r zaehlfeld"><?= $p['ist_menge'] !== null ? (int) $p['ist_menge'] : '' ?></td>
              <td class="r zaehlfeld"><?= $ve ? ($p['ist_lose'] !== null ? (int) $p['ist_lose'] : '') : '—' ?></td>
              <td class="bemerkfeld"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>

      <p class="nur-bildschirm">
        <a class="btn" href="inventur_zaehlen.php?id=<?= $id ?>">Zurück</a>
        <button class="btn btn--primaer" onclick="window.print()">Drucken</button>
      </p>
    </body>
    </html>
    <?php
    exit;
}

/* ===================================================================
   Erfassungsmaske
   =================================================================== */
$gesamtOffen = 0;
foreach ($orte as $o) {
    $gesamtOffen += (int) $o['offen'];
}

$titel = 'Zählen';
$aktiv = 'inventur';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1><?= h($inv['bezeichnung']) ?></h1>
  <span class="anzahl"><?= $gesamtOffen ?> Positionen noch offen</span>
  <span class="rechts">
    <a class="btn" href="inventur_zaehlen.php?id=<?= $id ?>&amp;format=druck">Zählliste drucken</a>
    <a class="btn" href="inventur.php?id=<?= $id ?>">Bericht</a>
  </span>
</div>

<?php if ($inv['abgeschlossen_am']): ?>
  <p class="flash flash--fehler">
    Diese Inventur wurde am <?= h(datum_de($inv['abgeschlossen_am'])) ?> abgeschlossen.
    Zählwerte lassen sich nicht mehr ändern.
  </p>
<?php endif; ?>

<div class="ortleiste">
  <a class="ortknopf<?= $ortFilter === '' ? ' is-active' : '' ?>" href="inventur_zaehlen.php?id=<?= $id ?>">
    alle Orte
  </a>
  <?php foreach ($orte as $o): ?>
    <a class="ortknopf<?= $ortFilter === $o['lagerort'] ? ' is-active' : '' ?>"
       href="inventur_zaehlen.php?id=<?= $id ?>&amp;ort=<?= urlencode((string) $o['lagerort']) ?>">
      <?= h((string) $o['lagerort']) ?>
      <span class="ortknopf-zahl<?= (int) $o['offen'] === 0 ? ' ist-fertig' : '' ?>">
        <?= (int) $o['offen'] ?>
      </span>
    </a>
  <?php endforeach; ?>
</div>

<p class="hinweis" style="margin:0 0 14px">
  Ein leeres Feld bedeutet „noch nicht gezählt“. Für „nichts mehr da“ trägst du eine 0 ein.
  Bei Ware in Gebinden zählst du links die vollen Gebinde und rechts die losen
  Einzelstücke aus einer angebrochenen Packung – die Differenz wird in
  Einzelstücken ausgewiesen.
</p>

<?php if (!$positionen): ?>
  <p class="leer">Für diese Auswahl sind keine Positionen offen.</p>
<?php else: ?>
<form method="post" action="inventur_zaehlen.php?id=<?= $id ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="ort" value="<?= h($ortFilter) ?>">

  <table class="tabelle tabelle--zaehlen">
    <thead>
      <tr>
        <th>Nr.</th><th>Artikel</th><th>Lagerort</th>
        <th style="text-align:right">Soll</th>
        <th style="text-align:right">Ist</th>
        <th style="text-align:right">lose</th>
        <th>Bemerkung</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($positionen as $p):
        $aid = (int) $p['artikel_id'];
        $d   = $p['ist_menge'] === null ? null : (int) $p['differenz_gesamt'];
        $ve  = (int) ($p['ve_inhalt'] ?? 0);
        $eE  = $p['ve_einheit'] ?: 'Stück';
        $eG  = $p['gebinde_einheit'] ?: 'Gebinde';
    ?>
      <tr<?= $d !== null && $d !== 0 ? ' class="stufe-faellig"' : '' ?>>
        <td data-label="Nr."><span class="invnr"><?= h($p['inventar_nr'] ?? '') ?></span></td>
        <td data-label="Artikel">
          <span class="name"><?= h($p['artikelname']) ?></span>
          <?php if ($ve): ?>
            <span class="notiz"><?= $ve ?> <?= h($eE) ?> je <?= h($eG) ?></span>
          <?php endif; ?>
        </td>
        <td data-label="Lagerort"><span class="platz-pfad"><?= h($p['lagerort'] ?? '') ?></span></td>
        <td class="zahl" data-label="Soll">
          <?= (int) $p['soll_menge'] ?><?php
          if ($ve): ?><small class="je"><?= h(mehrzahl($eG)) ?></small>
            <span class="notiz"><?= (int) $p['soll_gesamt'] ?> <?= h($eE) ?><?php
              if ((int) $p['soll_lose'] > 0) { echo ', ' . (int) $p['soll_lose'] . ' lose'; } ?></span>
          <?php endif; ?>
        </td>
        <td data-label="Ist<?= $ve ? ' (' . h(mehrzahl($eG)) . ')' : '' ?>" style="text-align:right">
          <input class="zaehlen-feld" type="number" min="0" step="1" inputmode="numeric"
                 name="ist[<?= $aid ?>]"
                 value="<?= $p['ist_menge'] !== null ? (int) $p['ist_menge'] : '' ?>"
                 <?= $inv['abgeschlossen_am'] ? 'disabled' : '' ?>
                 aria-label="Ist <?= $ve ? h($eG) : 'Menge' ?> <?= h($p['artikelname']) ?>">
          <?php if ($d !== null && $d !== 0): ?>
            <span class="diff"><?= $d > 0 ? '+' : '' ?><?= $d ?><?= $ve ? ' ' . h($eE) : '' ?></span>
          <?php endif; ?>
        </td>
        <td data-label="lose" style="text-align:right">
          <?php if ($ve): ?>
            <input class="zaehlen-feld zaehlen-feld--klein" type="number" min="0" step="1" inputmode="numeric"
                   name="ist_lose[<?= $aid ?>]"
                   value="<?= $p['ist_lose'] !== null ? (int) $p['ist_lose'] : '' ?>"
                   <?= $inv['abgeschlossen_am'] ? 'disabled' : '' ?>
                   aria-label="Lose <?= h($eE) ?> <?= h($p['artikelname']) ?>">
          <?php else: ?>
            <span class="notiz">—</span>
          <?php endif; ?>
        </td>
        <td data-label="Bemerkung">
          <input type="text" maxlength="255" name="bemerkung[<?= $aid ?>]"
                 value="<?= h($p['bemerkung'] ?? '') ?>"
                 <?= $inv['abgeschlossen_am'] ? 'disabled' : '' ?>
                 placeholder="optional" aria-label="Bemerkung <?= h($p['artikelname']) ?>">
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$inv['abgeschlossen_am']): ?>
    <div class="speicherleiste">
      <button class="btn btn--primaer" type="submit">Zählwerte speichern</button>
      <span class="hinweis"><?= count($positionen) ?> Positionen in dieser Ansicht</span>
    </div>
  <?php endif; ?>
</form>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
