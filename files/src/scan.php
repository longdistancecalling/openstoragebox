<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Buchen per Barcode.
 *
 * Ein USB-Handscanner verhält sich wie eine Tastatur: er tippt die
 * Ziffern und schickt ein Enter hinterher. Deshalb genügt ein
 * Textfeld mit Autofokus – es braucht keine Scanner-Bibliothek und
 * keine Kamerarechte. Am Handy tippt man die Nummer ein oder nutzt
 * die Suche.
 *
 * Der Ablauf ist bewusst zweistufig: scannen zeigt den Artikel mit
 * seinem Bestand, gebucht wird erst mit dem zweiten Klick. Ein
 * versehentlicher Scan verändert damit nichts.
 */

$ich      = benutzer();
$fehler   = [];
$treffer  = [];
$ean      = ean_saeubern((string) ($_POST['ean'] ?? $_GET['ean'] ?? ''));
$letzte   = null;

/* ===================================================================
   Buchung
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    csrf_check();

    $artikelId = ctype_digit((string) ($_POST['artikel_id'] ?? '')) ? (int) $_POST['artikel_id'] : 0;
    $richtung  = (string) ($_POST['richtung'] ?? '');
    $menge     = (string) ($_POST['menge'] ?? '1');
    $grund     = (string) ($_POST['grund'] ?? '');

    if (!in_array($richtung, ['zugang', 'abgang'], true)) {
        $fehler[] = 'Unbekannte Buchungsrichtung.';
    }
    if (!ctype_digit($menge) || (int) $menge < 1) {
        $fehler[] = 'Die Menge muss mindestens 1 sein.';
    }
    $gruende = $richtung === 'zugang' ? ZUGANG_GRUENDE : ENTNAHME_GRUENDE;
    if (!isset($gruende[$grund])) {
        $fehler[] = 'Unbekannter Grund.';
    }
    if ($richtung === 'abgang' && $grund === GRUND_LEIHE) {
        $fehler[] = 'Leihen bitte über „Entnahme buchen“ erfassen – dort wird festgehalten, wer das Material hat.';
    }

    if (!$fehler) {
        $menge = (int) $menge;
        try {
            // Transaktion mit Zeilensperre, wie bei der normalen Entnahme:
            // Bestand prüfen, ändern und protokollieren gehören zusammen.
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT artikelname, menge FROM artikel WHERE artikel_id = ? FOR UPDATE');
            $stmt->execute([$artikelId]);
            $art = $stmt->fetch();
            if (!$art) {
                throw new RuntimeException('Dieser Artikel existiert nicht.');
            }

            if ($richtung === 'abgang') {
                if ($menge > (int) $art['menge']) {
                    throw new RuntimeException(
                        'Es sind nur ' . (int) $art['menge'] . ' Stück im Bestand, entnommen werden sollen ' . $menge . '.'
                    );
                }
                $pdo->prepare('UPDATE artikel SET menge = menge - ? WHERE artikel_id = ?')
                    ->execute([$menge, $artikelId]);
                $pdo->prepare(
                    'INSERT INTO entnahme (artikel_id, menge, grund, entnommen_am, person, bezug, notiz, erfasst_von)
                     VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?)'
                )->execute([
                    $artikelId, $menge, $grund, $ich['anzeigename'],
                    trim((string) ($_POST['bezug'] ?? '')) ?: null,
                    'per Scan gebucht', (int) $ich['benutzer_id'],
                ]);
            } else {
                $pdo->prepare('UPDATE artikel SET menge = menge + ? WHERE artikel_id = ?')
                    ->execute([$menge, $artikelId]);
                $pdo->prepare(
                    'INSERT INTO zugang (artikel_id, menge, grund, datum, person, bezug, notiz, erfasst_von)
                     VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?)'
                )->execute([
                    $artikelId, $menge, $grund, $ich['anzeigename'],
                    trim((string) ($_POST['bezug'] ?? '')) ?: null,
                    'per Scan gebucht', (int) $ich['benutzer_id'],
                ]);
            }

            $neu = (int) $art['menge'] + ($richtung === 'zugang' ? $menge : -$menge);
            $pdo->commit();

            $letzte = [
                'text'  => ($richtung === 'zugang' ? '+' : '−') . $menge . ' × ' . $art['artikelname'],
                'stand' => $neu,
                'richtung' => $richtung,
            ];
            // EAN bleibt im Feld stehen, damit man denselben Artikel
            // mehrfach hintereinander buchen kann.
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $fehler[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Scan-Buchung: ' . $e->getMessage());
            $fehler[] = 'Die Buchung konnte nicht gespeichert werden.';
        }
    }
}

/* ===================================================================
   Artikel zur EAN suchen
   =================================================================== */
$eanBekannt = null;
if ($ean !== '') {
    $eanBekannt = ean_gueltig($ean);

    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE ean = ? ORDER BY ' . ORDER_LAGERORT);
    $stmt->execute([$ean]);
    $treffer = $stmt->fetchAll();

    if (!$treffer) {
        // Nicht als Fehler behandeln: eine unbekannte EAN ist der
        // Normalfall beim ersten Scan eines neuen Artikels.
        $stmt = $pdo->prepare(
            'SELECT artikel_id, inventar_nr, artikelname, menge, lagerort_text
             FROM v_artikel WHERE ean IS NULL ORDER BY artikelname'
        );
        $stmt->execute();
        $ohneEan = $stmt->fetchAll();
    }
}

/* ===================================================================
   EAN einem vorhandenen Artikel zuordnen
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'zuordnen') {
    csrf_check();
    $artikelId = ctype_digit((string) ($_POST['artikel_id'] ?? '')) ? (int) $_POST['artikel_id'] : 0;
    if ($ean === '' || $artikelId === 0) {
        $fehler[] = 'EAN oder Artikel fehlt.';
    } else {
        $pdo->prepare('UPDATE artikel SET ean = ? WHERE artikel_id = ?')->execute([$ean, $artikelId]);
        flash('EAN ' . $ean . ' zugeordnet.');
        redirect('scan.php?ean=' . urlencode($ean));
    }
}

$titel = 'Scannen';
$aktiv = 'scan';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Scannen</h1>
  <span class="anzahl">gebucht als <?= h($ich['anzeigename']) ?></span>
</div>

<?php if ($letzte): ?>
  <p class="flash flash--<?= $letzte['richtung'] === 'zugang' ? 'ok' : 'ok' ?>">
    <strong><?= h($letzte['text']) ?></strong> gebucht · neuer Bestand: <?= (int) $letzte['stand'] ?>
  </p>
<?php endif; ?>

<?php if ($fehler): ?>
  <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<form class="scanfeld" method="get" action="scan.php">
  <label for="ean">Barcode scannen oder eingeben</label>
  <input type="text" id="ean" name="ean" value="<?= h($ean) ?>" autofocus
         inputmode="numeric" autocomplete="off" pattern="[0-9 \-]*"
         placeholder="EAN">
  <button class="btn btn--primaer" type="submit">Suchen</button>
</form>

<?php if ($ean !== '' && $eanBekannt === false): ?>
  <p class="flash flash--fehler">
    Die Prüfziffer von <?= h($ean) ?> stimmt nicht. Beim Abtippen passiert das leicht –
    bitte noch einmal kontrollieren. Buchen lässt sich trotzdem, falls es ein
    hausinterner Code ist.
  </p>
<?php endif; ?>

<?php if ($ean !== '' && $treffer): ?>

  <?php foreach ($treffer as $a):
      $leihe = (int) $a['verliehen_offen'];
  ?>
  <div class="scan-karte">
    <div class="scan-kopf">
      <div>
        <span class="invnr"><?= h($a['inventar_nr']) ?></span>
        <strong class="scan-name"><?= h($a['artikelname']) ?></strong>
        <div style="margin-top:6px"><?= lagerort($a) ?></div>
        <?php if ($a['zusatzfelder']): ?>
          <div class="zusatz" style="margin-top:4px"><?= h($a['zusatzfelder']) ?></div>
        <?php endif; ?>
        <?php if ($a['faellig_am']): ?>
          <?php $stufe = faellig_stufe((int) $a['tage_bis_faellig']); ?>
          <div style="margin-top:6px">
            <span class="marke marke--<?= $stufe === 'faellig' ? 'rot' : ($stufe === 'bald' ? 'amber' : 'gruen') ?>">
              <?= h(faellig_label($a['faellig_typ'])) ?> <?= h(datum_de($a['faellig_am'])) ?>
            </span>
          </div>
        <?php endif; ?>
      </div>
      <div class="scan-bestand">
        <span class="scan-zahl"><?= (int) $a['menge'] ?></span>
        <span class="scan-label">im Bestand</span>
        <?php if ($leihe > 0): ?><span class="leihe"><?= $leihe ?> verliehen</span><?php endif; ?>
      </div>
    </div>

    <div class="scan-aktionen">
      <form method="post" action="scan.php" class="scan-form">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="buchen">
        <input type="hidden" name="ean" value="<?= h($ean) ?>">
        <input type="hidden" name="artikel_id" value="<?= (int) $a['artikel_id'] ?>">
        <input type="hidden" name="richtung" value="abgang">
        <div class="scan-zeile">
          <input type="number" name="menge" value="1" min="1" step="1" aria-label="Menge entnehmen">
          <select name="grund" aria-label="Grund der Entnahme">
            <?php foreach (ENTNAHME_GRUENDE as $w => $l): if ($w === GRUND_LEIHE) continue; ?>
              <option value="<?= h($w) ?>"><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn--gefahr" type="submit" <?= (int) $a['menge'] === 0 ? 'disabled' : '' ?>>
            − Entnehmen
          </button>
        </div>
      </form>

      <form method="post" action="scan.php" class="scan-form">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="buchen">
        <input type="hidden" name="ean" value="<?= h($ean) ?>">
        <input type="hidden" name="artikel_id" value="<?= (int) $a['artikel_id'] ?>">
        <input type="hidden" name="richtung" value="zugang">
        <div class="scan-zeile">
          <input type="number" name="menge" value="1" min="1" step="1" aria-label="Menge hinzufügen">
          <select name="grund" aria-label="Grund des Zugangs">
            <?php foreach (ZUGANG_GRUENDE as $w => $l): ?>
              <option value="<?= h($w) ?>"><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn--primaer" type="submit">+ Hinzufügen</button>
        </div>
      </form>
    </div>

    <p class="hinweis">
      Gebucht wird auf deinen Namen und das heutige Datum.
      Für Leihen, abweichende Daten oder eine Notiz nutze
      <a href="entnahme.php?artikel=<?= (int) $a['artikel_id'] ?>">Entnahme buchen</a>.
    </p>
  </div>
  <?php endforeach; ?>

<?php elseif ($ean !== ''): ?>

  <div class="scan-karte">
    <h2 class="scan-name">EAN <?= h(ean_anzeigen($ean)) ?> ist unbekannt</h2>
    <p class="hinweis">
      Ordne sie einem vorhandenen Artikel zu – dann funktioniert der Scan ab sofort –
      oder lege den Artikel neu an.
    </p>

    <?php if (!empty($ohneEan)): ?>
      <form method="post" action="scan.php" class="form" style="max-width:none;border:0;padding:0">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="zuordnen">
        <input type="hidden" name="ean" value="<?= h($ean) ?>">
        <div class="zeile">
          <label for="artikel_id">Artikel ohne EAN</label>
          <select id="artikel_id" name="artikel_id" required size="<?= min(8, max(2, count($ohneEan))) ?>">
            <?php foreach ($ohneEan as $a): ?>
              <option value="<?= (int) $a['artikel_id'] ?>">
                <?= h($a['inventar_nr']) ?> — <?= h($a['artikelname']) ?>
                (<?= (int) $a['menge'] ?> Stück<?= $a['lagerort_text'] ? ', ' . h($a['lagerort_text']) : '' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="knoepfe">
          <button class="btn btn--primaer" type="submit">EAN zuordnen</button>
          <a class="btn" href="artikel.php?ean=<?= urlencode($ean) ?>">Neuen Artikel anlegen</a>
        </div>
      </form>
    <?php else: ?>
      <div class="knoepfe">
        <a class="btn btn--primaer" href="artikel.php?ean=<?= urlencode($ean) ?>">Neuen Artikel anlegen</a>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <p class="leer">
    Barcode scannen oder eintippen. Ein USB-Handscanner funktioniert direkt –
    er verhält sich wie eine Tastatur und schickt die Nummer samt Enter.
  </p>

<?php endif; ?>

<script>
// Nach jeder Buchung zurück ins Scanfeld und Inhalt markieren, damit der
// nächste Scan ihn einfach überschreibt.
(function () {
  const feld = document.getElementById('ean');
  if (feld) { feld.focus(); feld.select(); }
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
