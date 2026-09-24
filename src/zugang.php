<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Wareneingang buchen – Spiegelbild von entnahme.php.
 *
 * Ein wichtiger Unterschied: hier sind auch Artikel mit Bestand 0
 * wählbar. Genau die will man einbuchen, wenn Nachschub kommt.
 */

$artikelId = isset($_GET['artikel']) && ctype_digit((string) $_GET['artikel'])
           ? (int) $_GET['artikel'] : null;
$fehler = [];

$daten = [
    'menge'  => '1',
    'einheit'=> 'gebinde',
    'grund'  => 'beschaffung',
    'datum'  => date('Y-m-d'),
    'person' => benutzer()['anzeigename'],
    'bezug'  => '',
    'notiz'  => '',
];

/* ===================================================================
   POST
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $artikelId = ctype_digit((string) ($_POST['artikel_id'] ?? ''))
               ? (int) $_POST['artikel_id'] : null;

    foreach (array_keys($daten) as $feld) {
        $daten[$feld] = trim((string) ($_POST[$feld] ?? ''));
    }

    if ($artikelId === null) {
        $fehler[] = 'Bitte einen Artikel auswählen.';
    }
    if (!ctype_digit($daten['menge']) || (int) $daten['menge'] < 1) {
        $fehler[] = 'Die Menge muss mindestens 1 sein.';
    }
    if (!isset(ZUGANG_GRUENDE[$daten['grund']])) {
        $fehler[] = 'Unbekannter Grund.';
    }
    if (!DateTimeImmutable::createFromFormat('Y-m-d', $daten['datum'])) {
        $fehler[] = 'Das Datum ist ungültig.';
    }

    if (!$fehler && $artikelId !== null) {
        try {
            // Wie bei der Entnahme: Bestand ändern und protokollieren
            // gehören in eine Transaktion, die Zeilensperre verhindert,
            // dass zwei gleichzeitige Buchungen sich überschreiben.
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT artikelname, menge, lose, ve_inhalt, ve_einheit, gebinde_einheit
                 FROM artikel WHERE artikel_id = ? FOR UPDATE'
            );
            $stmt->execute([$artikelId]);
            $art = $stmt->fetch();
            if (!$art) {
                throw new RuntimeException('Dieser Artikel existiert nicht.');
            }

            $menge   = (int) $daten['menge'];
            $einheit = empty($art['ve_inhalt']) ? 'einzeln' : $daten['einheit'];

            [$neueMenge, $neueLose] = bestand_rechnen($art, $menge, $einheit, true);

            $pdo->prepare('UPDATE artikel SET menge = ?, lose = ? WHERE artikel_id = ?')
                ->execute([$neueMenge, $neueLose, $artikelId]);

            $pdo->prepare(
                'INSERT INTO zugang (artikel_id, menge, einheit, grund, datum, person, bezug, notiz, erfasst_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $artikelId, $menge, $einheit, $daten['grund'], $daten['datum'],
                $daten['person'] !== '' ? $daten['person'] : null,
                $daten['bezug']  !== '' ? $daten['bezug']  : null,
                $daten['notiz']  !== '' ? $daten['notiz']  : null,
                (int) benutzer()['benutzer_id'],
            ]);

            $pdo->commit();

            flash(einheit_text($art, $einheit, $menge) . ' ' . $art['artikelname']
                . ' eingebucht (' . zugang_label($daten['grund']) . ').');
            redirect('entnahmen.php?richtung=zugang');

        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $fehler[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Zugang buchen: ' . $e->getMessage());
            $fehler[] = 'Der Zugang konnte nicht gebucht werden.';
        }
    }
}

/* ===================================================================
   Artikelauswahl – hier ohne Bestandsfilter
   =================================================================== */
$suche = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));

$sql   = 'SELECT * FROM v_artikel';
$werte = [];
if ($suche !== '') {
    $sql .= " WHERE CONCAT_WS(' ', inventar_nr, IFNULL(ean,''), artikelname, IFNULL(kiste_nr,''), "
          . "IFNULL(lagerort_text,''), IFNULL(kategorie,''), IFNULL(notiz,'')) LIKE :q";
    $werte[':q'] = '%' . $suche . '%';
}
$sql .= ' ORDER BY artikelname';

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$artikelListe = $stmt->fetchAll();

if ($artikelId === null && $suche !== '' && count($artikelListe) === 1) {
    $artikelId = (int) $artikelListe[0]['artikel_id'];
}

$gesamt = (int) $pdo->query('SELECT COUNT(*) FROM artikel')->fetchColumn();

$gewaehlt = null;
if ($artikelId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE artikel_id = ?');
    $stmt->execute([$artikelId]);
    $gewaehlt = $stmt->fetch() ?: null;
}

$titel = 'Zugang buchen';
$aktiv = 'entnahmen';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Zugang buchen</h1>
  <span class="rechts">
    <a class="btn" href="entnahme.php">Entnahme buchen</a>
    <a class="btn" href="entnahmen.php">Protokoll</a>
  </span>
</div>

<?php if ($fehler): ?>
  <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<form class="filter" method="get" action="zugang.php">
  <div class="feld feld--breit">
    <label for="q">Artikel suchen</label>
    <input type="search" id="q" name="q" value="<?= h($suche) ?>" autofocus
           placeholder="Name, Nummer, EAN, Kiste oder Kategorie">
  </div>
  <button class="btn" type="submit">Suchen</button>
  <?php if ($suche !== ''): ?><a class="btn" href="zugang.php">Alle zeigen</a><?php endif; ?>
  <span class="hinweis" style="align-self:center">
    <?= count($artikelListe) ?> von <?= $gesamt ?> Artikeln
  </span>
</form>

<?php if (!$artikelListe): ?>
  <p class="leer">
    <?php if ($suche !== ''): ?>
      Kein Artikel passt zu „<?= h($suche) ?>“. <a href="zugang.php">Alle anzeigen</a>
      oder <a href="artikel.php">neuen Artikel anlegen</a>.
    <?php else: ?>
      Es gibt noch keine Artikel. <a href="artikel.php">Ersten Artikel anlegen</a>
    <?php endif; ?>
  </p>
<?php else: ?>

<?php if ($gewaehlt): ?>
  <div class="karte">
    <div>
      <span class="invnr"><?= h($gewaehlt['inventar_nr']) ?></span>
      <strong style="margin-left:8px"><?= h($gewaehlt['artikelname']) ?></strong>
    </div>
    <div style="margin-top:6px"><?= lagerort($gewaehlt) ?></div>
    <div class="karte-zahl">
      <?= $gewaehlt['hat_gebinde'] ? (int) $gewaehlt['menge'] : (int) $gewaehlt['gesamt_einzeln'] ?>
      <small><?= $gewaehlt['hat_gebinde']
          ? h(mehrzahl($gewaehlt['gebinde_einheit'] ?: 'Gebinde')) . ' · ' . (int) $gewaehlt['gesamt_einzeln'] . ' ' . h($gewaehlt['ve_einheit'] ?: 'Stück')
          : 'im Bestand' ?></small>
    </div>
  </div>
<?php endif; ?>

<form class="form" method="post" action="zugang.php">
  <?= csrf_field() ?>
  <input type="hidden" name="q" value="<?= h($suche) ?>">

  <div class="zeile">
    <label for="artikel_id">Artikel<?= count($artikelListe) > 12 ? ' <span class="hinweis-inline">tippen filtert die Liste</span>' : '' ?></label>
    <?php if (count($artikelListe) > 12): ?>
      <input type="text" id="schnellfilter" placeholder="Liste eingrenzen …" autocomplete="off" style="margin-bottom:6px">
    <?php endif; ?>
    <select id="artikel_id" name="artikel_id" required size="<?= min(10, max(1, count($artikelListe))) ?>">
      <?php foreach ($artikelListe as $a): ?>
        <option value="<?= (int) $a['artikel_id'] ?>"
          data-suchtext="<?= h(mb_strtolower($a['inventar_nr'] . ' ' . $a['artikelname'] . ' ' . ($a['kategorie'] ?? '') . ' ' . ($a['lagerort_text'] ?? ''))) ?>"
          data-gebinde="<?= h((string) ($a['gebinde_einheit'] ?? '')) ?>"
          data-einzeln="<?= h((string) ($a['ve_einheit'] ?? '')) ?>"
          <?= $artikelId === (int) $a['artikel_id'] ? ' selected' : '' ?>>
          <?= h($a['inventar_nr']) ?> — <?= h($a['artikelname']) ?>
          (<?= h(bestand_text($a)) ?><?= $a['lagerort_text'] ? ', ' . h($a['lagerort_text']) : '' ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="menge">Menge</label>
      <input type="number" id="menge" name="menge" min="1" step="1" required value="<?= h($daten['menge']) ?>">
    </div>
    <div class="zeile" id="feld-einheit"<?= $gewaehlt && $gewaehlt['hat_gebinde'] ? '' : ' hidden' ?>>
      <label for="einheit">Einheit</label>
      <select id="einheit" name="einheit">
        <option value="gebinde" <?= $daten['einheit'] === 'gebinde' ? 'selected' : '' ?>>
          <?= $gewaehlt && $gewaehlt['gebinde_einheit'] ? h(mehrzahl($gewaehlt['gebinde_einheit'])) : 'Gebinde' ?>
        </option>
        <option value="einzeln" <?= $daten['einheit'] === 'einzeln' ? 'selected' : '' ?>>
          <?= $gewaehlt && $gewaehlt['ve_einheit'] ? h($gewaehlt['ve_einheit']) : 'Einzelstücke' ?>
        </option>
      </select>
    </div>
    <div class="zeile">
      <label for="grund">Grund</label>
      <select id="grund" name="grund" required>
        <?php foreach (ZUGANG_GRUENDE as $wert => $label): ?>
          <option value="<?= h($wert) ?>" <?= $daten['grund'] === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="zeile">
      <label for="datum">Datum</label>
      <input type="date" id="datum" name="datum" required value="<?= h($daten['datum']) ?>">
    </div>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="person">Person</label>
      <input type="text" id="person" name="person" maxlength="80" value="<?= h($daten['person']) ?>">
    </div>
    <div class="zeile">
      <label for="bezug">Bezug</label>
      <input type="text" id="bezug" name="bezug" maxlength="120" value="<?= h($daten['bezug']) ?>"
             placeholder="Lieferant, Rechnung, Spender">
    </div>
  </div>

  <p class="hinweis">
    <strong>Korrektur</strong> ist für Zählfehler gedacht: wenn im Regal mehr liegt als im
    System steht. Für regelmäßige Abgleiche ist die
    <a href="inventur.php">Inventur</a> der bessere Weg – sie hält Soll und Ist nebeneinander fest.
  </p>

  <div class="zeile">
    <label for="notiz">Notiz</label>
    <input type="text" id="notiz" name="notiz" maxlength="255" value="<?= h($daten['notiz']) ?>">
  </div>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Zugang buchen</button>
    <a class="btn" href="index.php">Abbrechen</a>
  </div>
</form>

<script>
/*
 * Einheitenauswahl an den gewählten Artikel anpassen.
 *
 * Vorher richtete sich das Feld nur nach dem vorausgewählten Artikel und
 * blieb versteckt, wenn man erst in der Liste einen Gebindeartikel
 * anklickte – dann war nicht wählbar, ob Kisten oder Flaschen gebucht
 * werden, und die Buchung landete stillschweigend in der falschen
 * Einheit.
 */
(function () {
  const sel  = document.getElementById('artikel_id');
  const zeile = document.getElementById('feld-einheit');
  const eSel = document.getElementById('einheit');
  if (!sel || !zeile || !eSel) return;
  function anpassen() {
    const o = sel.selectedOptions[0];
    const g = o ? (o.dataset.gebinde || '') : '';
    const e = o ? (o.dataset.einzeln || '') : '';
    const hat = g !== '' || e !== '';
    zeile.hidden = !hat;
    if (hat) {
      eSel.options[0].textContent = g || 'Gebinde';
      eSel.options[1].textContent = e || 'Einzelstücke';
    }
  }
  sel.addEventListener('change', anpassen);
  anpassen();
})();

(function () {
  const feld = document.getElementById('schnellfilter');
  if (!feld) return;
  const select = document.getElementById('artikel_id');
  const alle = Array.from(select.options);
  feld.addEventListener('input', () => {
    const suche = feld.value.trim().toLowerCase();
    let sichtbar = 0;
    alle.forEach(o => {
      const treffer = suche === '' || (o.dataset.suchtext || '').includes(suche);
      o.hidden = !treffer;
      if (treffer) sichtbar++;
    });
    if (select.selectedOptions.length && select.selectedOptions[0].hidden) {
      select.selectedIndex = -1;
    }
    select.size = Math.min(10, Math.max(1, sichtbar));
  });
})();
</script>

<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
