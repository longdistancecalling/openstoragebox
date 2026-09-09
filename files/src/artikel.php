<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

$id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$fehler = [];

// Vorbelegung für ein neues Formular.
$daten = [
    'inventar_nr'  => '',
    'artikelname'  => '',
    'ortart'       => 'kiste',   // 'kiste' | 'frei' | 'ohne'
    'kiste_id'     => '',
    'standort_frei'=> '',
    'kategorie_id' => '',
    'menge'        => '1',
    'faellig_am'   => '',
    'faellig_typ'  => '',
    'notiz'        => '',
];

/* ===================================================================
   POST – speichern oder löschen
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    /* ---- Löschen -------------------------------------------------- */
    if ($aktion === 'loeschen' && $id !== null) {
        erfordere('admin');
        // Das Entnahmeprotokoll hängt per ON DELETE CASCADE daran und
        // verschwindet mit. Wer die Historie behalten will, setzt den
        // Bestand stattdessen auf 0.
        $pdo->prepare('DELETE FROM artikel WHERE artikel_id = ?')->execute([$id]);
        flash('Artikel gelöscht.');
        redirect('index.php');
    }

    /* ---- Speichern ------------------------------------------------ */
    foreach (array_keys($daten) as $feld) {
        $daten[$feld] = trim((string) ($_POST[$feld] ?? ''));
    }

    if ($daten['artikelname'] === '') {
        $fehler[] = 'Der Artikelname fehlt.';
    } elseif (mb_strlen($daten['artikelname']) > 120) {
        $fehler[] = 'Der Artikelname ist länger als 120 Zeichen.';
    }

    // Lagerort: genau eine der drei Varianten.
    if (!in_array($daten['ortart'], ['kiste', 'frei', 'ohne'], true)) {
        $daten['ortart'] = 'kiste';
    }
    if ($daten['ortart'] === 'kiste') {
        if ($daten['kiste_id'] === '' || !ctype_digit($daten['kiste_id'])) {
            $fehler[] = 'Bitte eine Kiste auswählen oder auf einen freien Standort umstellen.';
        }
        $daten['standort_frei'] = '';
    } elseif ($daten['ortart'] === 'frei') {
        if ($daten['standort_frei'] === '') {
            $fehler[] = 'Bitte den freien Standort eintragen.';
        }
        $daten['kiste_id'] = '';
    } else {
        $daten['kiste_id']      = '';
        $daten['standort_frei'] = '';
    }

    if (!ctype_digit($daten['menge'])) {
        $fehler[] = 'Der Bestand muss eine ganze Zahl ab 0 sein.';
    }

    // Der CHECK in der Datenbank verlangt beides oder nichts.
    if ($daten['faellig_am'] !== '' && $daten['faellig_typ'] === '') {
        $fehler[] = 'Zum Datum fehlt die Angabe, ob es ein Ablauf- oder ein Prüfdatum ist.';
    }
    if ($daten['faellig_typ'] !== '' && $daten['faellig_am'] === '') {
        $fehler[] = 'Zur Art der Fälligkeit fehlt das Datum.';
    }
    if ($daten['faellig_typ'] !== '' && !in_array($daten['faellig_typ'], ['ablauf', 'pruefung'], true)) {
        $fehler[] = 'Unbekannte Art der Fälligkeit.';
    }

    if (!$fehler) {
        $werte = [
            $daten['artikelname'],
            $daten['kiste_id']      !== '' ? (int) $daten['kiste_id'] : null,
            $daten['standort_frei'] !== '' ? $daten['standort_frei']  : null,
            $daten['kategorie_id']  !== '' ? (int) $daten['kategorie_id'] : null,
            (int) $daten['menge'],
            $daten['faellig_am']  !== '' ? $daten['faellig_am']  : null,
            $daten['faellig_typ'] !== '' ? $daten['faellig_typ'] : null,
            $daten['notiz'] !== '' ? $daten['notiz'] : null,
        ];

        try {
            if ($id === null) {
                /*
                 * Anlegen in einer Transaktion: erst der Datensatz, dann
                 * die Inventarnummer aus der vergebenen ID. So kann die
                 * Nummer nicht doppelt entstehen – ein vorher berechnetes
                 * MAX()+1 würde bei zwei gleichzeitigen Anlagen kollidieren.
                 */
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO artikel
                       (artikelname, kiste_id, standort_frei, kategorie_id, menge,
                        faellig_am, faellig_typ, notiz)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute($werte);
                $neueId = (int) $pdo->lastInsertId();

                $nummer = $daten['inventar_nr'] !== ''
                        ? $daten['inventar_nr']
                        : inventar_nr_aus_id($neueId);

                $pdo->prepare('UPDATE artikel SET inventar_nr = ? WHERE artikel_id = ?')
                    ->execute([$nummer, $neueId]);

                $pdo->commit();
                flash("Artikel $nummer angelegt.");
            } else {
                $nummer = $daten['inventar_nr'] !== ''
                        ? $daten['inventar_nr']
                        : inventar_nr_aus_id($id);

                $stmt = $pdo->prepare(
                    'UPDATE artikel SET
                        artikelname = ?, kiste_id = ?, standort_frei = ?, kategorie_id = ?,
                        menge = ?, faellig_am = ?, faellig_typ = ?, notiz = ?, inventar_nr = ?
                     WHERE artikel_id = ?'
                );
                $stmt->execute([...$werte, $nummer, $id]);
                flash('Änderungen gespeichert.');
            }
            redirect('index.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $fehler[] = 'Die Inventarnummer „' . $daten['inventar_nr'] . '“ ist bereits vergeben.';
            } else {
                error_log('Artikel speichern: ' . $e->getMessage());
                $fehler[] = 'Der Datensatz konnte nicht gespeichert werden.';
            }
        }
    }
}

/* ===================================================================
   GET – bestehenden Datensatz laden
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $id !== null) {
    $stmt = $pdo->prepare('SELECT * FROM artikel WHERE artikel_id = ?');
    $stmt->execute([$id]);
    $vorhanden = $stmt->fetch();

    if (!$vorhanden) {
        flash('Dieser Artikel existiert nicht (mehr).', 'fehler');
        redirect('index.php');
    }

    $daten = [
        'inventar_nr'  => (string) ($vorhanden['inventar_nr'] ?? ''),
        'artikelname'  => (string) $vorhanden['artikelname'],
        'ortart'       => $vorhanden['kiste_id'] !== null ? 'kiste'
                        : ($vorhanden['standort_frei'] !== null ? 'frei' : 'ohne'),
        'kiste_id'     => (string) ($vorhanden['kiste_id'] ?? ''),
        'standort_frei'=> (string) ($vorhanden['standort_frei'] ?? ''),
        'kategorie_id' => (string) ($vorhanden['kategorie_id'] ?? ''),
        'menge'        => (string) $vorhanden['menge'],
        'faellig_am'   => (string) ($vorhanden['faellig_am'] ?? ''),
        'faellig_typ'  => (string) ($vorhanden['faellig_typ'] ?? ''),
        'notiz'        => (string) ($vorhanden['notiz'] ?? ''),
    ];
}

$kisten = $pdo->query('SELECT kiste_id, kiste_nr, lager, regal, fach FROM kiste ORDER BY ' . ORDER_LAGERPLATZ)->fetchAll();
$kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();

$titel = $id === null ? 'Artikel anlegen' : 'Artikel bearbeiten';
$aktiv = 'index';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1><?= h($titel) ?></h1>
  <?php if ($id !== null && $daten['inventar_nr'] !== ''): ?>
    <span class="anzahl"><?= h($daten['inventar_nr']) ?></span>
  <?php endif; ?>
</div>

<?php if ($fehler): ?>
  <ul class="fehlerliste">
    <?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<form class="form" method="post" action="artikel.php<?= $id !== null ? '?id=' . $id : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="aktion" value="speichern">

  <div class="paar">
    <div class="zeile" style="flex:3 1 300px">
      <label for="artikelname">Artikelname</label>
      <input type="text" id="artikelname" name="artikelname" maxlength="120" required
             value="<?= h($daten['artikelname']) ?>" autofocus>
    </div>
    <div class="zeile">
      <label for="inventar_nr">Inventarnummer</label>
      <input type="text" id="inventar_nr" name="inventar_nr" maxlength="20"
             value="<?= h($daten['inventar_nr']) ?>" placeholder="automatisch">
    </div>
  </div>
  <p class="hinweis">
    Leer lassen vergibt automatisch eine Nummer im Format
    <code><?= h(INVENTAR_PREFIX) ?>00042</code>. Eigene Nummern sind erlaubt, müssen aber eindeutig sein.
  </p>

  <fieldset class="ortwahl">
    <legend>Lagerort</legend>
    <label class="radio">
      <input type="radio" name="ortart" value="kiste" data-ort="kiste"
             <?= $daten['ortart'] === 'kiste' ? 'checked' : '' ?>>
      <span>in einer Kiste</span>
    </label>
    <label class="radio">
      <input type="radio" name="ortart" value="frei" data-ort="frei"
             <?= $daten['ortart'] === 'frei' ? 'checked' : '' ?>>
      <span>freier Standort</span>
    </label>
    <label class="radio">
      <input type="radio" name="ortart" value="ohne" data-ort="ohne"
             <?= $daten['ortart'] === 'ohne' ? 'checked' : '' ?>>
      <span>noch kein Ort</span>
    </label>
  </fieldset>

  <div class="zeile" id="feld-kiste">
    <label for="kiste_id">Kiste</label>
    <select id="kiste_id" name="kiste_id">
      <option value="">– bitte wählen –</option>
      <?php foreach ($kisten as $k): ?>
        <option value="<?= (int) $k['kiste_id'] ?>"
          <?= $daten['kiste_id'] === (string) $k['kiste_id'] ? ' selected' : '' ?>>
          <?= h($k['kiste_nr']) ?> — <?= h($k['lager'] . ' / ' . $k['regal'] . ' / ' . $k['fach']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="zeile" id="feld-frei">
    <label for="standort_frei">Freier Standort</label>
    <input type="text" id="standort_frei" name="standort_frei" maxlength="120"
           value="<?= h($daten['standort_frei']) ?>" placeholder="z. B. Halle B, Stellplatz 3">
    <p class="hinweis">Für alles, was in keine Kiste passt: Zelte, Feldbetten, Aggregate, Anhänger.</p>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="kategorie_id">Kategorie</label>
      <select id="kategorie_id" name="kategorie_id">
        <option value="">– ohne –</option>
        <?php foreach ($kategorien as $k): ?>
          <option value="<?= (int) $k['kategorie_id'] ?>"
            <?= $daten['kategorie_id'] === (string) $k['kategorie_id'] ? ' selected' : '' ?>>
            <?= h($k['bezeichnung']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="zeile">
      <label for="menge">Bestand</label>
      <input type="number" id="menge" name="menge" min="0" step="1" value="<?= h($daten['menge']) ?>">
    </div>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="faellig_am">Datum</label>
      <input type="date" id="faellig_am" name="faellig_am" value="<?= h($daten['faellig_am']) ?>">
    </div>
    <div class="zeile">
      <label for="faellig_typ">Art des Datums</label>
      <select id="faellig_typ" name="faellig_typ">
        <option value="">– keins –</option>
        <option value="ablauf"   <?= $daten['faellig_typ'] === 'ablauf'   ? 'selected' : '' ?>>Ablaufdatum</option>
        <option value="pruefung" <?= $daten['faellig_typ'] === 'pruefung' ? 'selected' : '' ?>>Nächste Prüfung</option>
      </select>
    </div>
  </div>

  <div class="zeile">
    <label for="notiz">Notiz</label>
    <input type="text" id="notiz" name="notiz" maxlength="255" value="<?= h($daten['notiz']) ?>">
  </div>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Speichern</button>
    <a class="btn" href="index.php">Abbrechen</a>
    <?php if ($id !== null): ?>
      <a class="btn weg" href="entnahme.php?artikel=<?= $id ?>">Entnahme buchen</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($id !== null && darf('admin')): ?>
  <form class="form" method="post" action="artikel.php?id=<?= $id ?>" style="margin-top:16px"
        onsubmit="return confirm('Artikel löschen? Das Entnahmeprotokoll dieses Artikels verschwindet mit.');">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="loeschen">
    <button class="btn btn--gefahr" type="submit">Artikel löschen</button>
    <p class="hinweis" style="margin:8px 0 0">
      Soll die Historie erhalten bleiben, setze stattdessen den Bestand auf 0.
    </p>
  </form>
<?php endif; ?>

<script>
// Zeigt nur das Feld, das zur gewählten Lagerort-Art gehört.
// Ohne JavaScript bleiben beide sichtbar – die serverseitige Prüfung
// entscheidet ohnehin, welches Feld ausgewertet wird.
(function () {
  const kiste = document.getElementById('feld-kiste');
  const frei  = document.getElementById('feld-frei');
  function zeigen() {
    const wahl = document.querySelector('input[name="ortart"]:checked').value;
    kiste.hidden = wahl !== 'kiste';
    frei.hidden  = wahl !== 'frei';
  }
  document.querySelectorAll('input[name="ortart"]').forEach(r => r.addEventListener('change', zeigen));
  zeigen();
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
