<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

$artikelId = isset($_GET['artikel']) && ctype_digit((string) $_GET['artikel'])
           ? (int) $_GET['artikel'] : null;
$fehler = [];

$daten = [
    'menge'        => '1',
    'grund'        => 'einsatz',
    'entnommen_am' => date('Y-m-d'),
    // Wer bucht, ist angemeldet – der Name steht also schon fest.
    // Überschreibbar, weil man auch für jemand anderen buchen kann.
    'person'       => benutzer()['anzeigename'],
    'bezug'        => '',
    'notiz'        => '',
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
    if (!isset(ENTNAHME_GRUENDE[$daten['grund']])) {
        $fehler[] = 'Unbekannter Entnahmegrund.';
    }
    if (!DateTimeImmutable::createFromFormat('Y-m-d', $daten['entnommen_am'])) {
        $fehler[] = 'Das Entnahmedatum ist ungültig.';
    }
    if ($daten['grund'] === GRUND_LEIHE && $daten['person'] === '') {
        $fehler[] = 'Bei einer Leihe muss stehen, wer das Material hat.';
    }

    if (!$fehler && $artikelId !== null) {
        try {
            /*
             * Transaktion: Bestand prüfen, Bestand verringern und die
             * Bewegung protokollieren gehören zusammen. Bricht ein Teil
             * ab, darf nichts davon in der Datenbank landen – sonst
             * stimmt entweder der Bestand nicht oder es fehlt der Beleg.
             *
             * FOR UPDATE sperrt die Artikelzeile bis zum Commit. Ohne
             * die Sperre könnten zwei gleichzeitige Entnahmen denselben
             * Ausgangsbestand lesen und den Bestand ins Minus schicken.
             */
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT artikelname, menge FROM artikel WHERE artikel_id = ? FOR UPDATE');
            $stmt->execute([$artikelId]);
            $art = $stmt->fetch();

            if (!$art) {
                throw new RuntimeException('Dieser Artikel existiert nicht.');
            }

            $menge = (int) $daten['menge'];
            if ($menge > (int) $art['menge']) {
                throw new RuntimeException(
                    'Es sind nur ' . (int) $art['menge'] . ' Stück im Bestand, '
                    . "entnommen werden sollen $menge."
                );
            }

            $pdo->prepare('UPDATE artikel SET menge = menge - ? WHERE artikel_id = ?')
                ->execute([$menge, $artikelId]);

            $pdo->prepare(
                'INSERT INTO entnahme (artikel_id, menge, grund, entnommen_am, person, bezug, notiz, erfasst_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $artikelId,
                $menge,
                $daten['grund'],
                $daten['entnommen_am'],
                $daten['person'] !== '' ? $daten['person'] : null,
                $daten['bezug']  !== '' ? $daten['bezug']  : null,
                $daten['notiz']  !== '' ? $daten['notiz']  : null,
                (int) benutzer()['benutzer_id'],
            ]);

            // Vor dem Commit abfragen: danach liefert lastInsertId() je nach
            // Treiber 0, weil die Verbindung den Wert der abgeschlossenen
            // Transaktion nicht mehr führt.
            $neueId = (int) $pdo->lastInsertId();

            $pdo->commit();

            $text = $menge . '× ' . $art['artikelname'] . ' entnommen ('
                  . grund_label($daten['grund']) . ').';
            if ($daten['grund'] === GRUND_LEIHE) {
                $text .= ' Die Rückgabe steht unter „Bewegungen" offen.';
            }
            flash($text);
            // Bei einer Leihe direkt zum Schein – der wird unterschrieben,
            // solange der Entleiher noch danebensteht.
            redirect($daten['grund'] === GRUND_LEIHE
                ? 'schein.php?id=' . $neueId
                : 'entnahmen.php');

        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $fehler[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Entnahme buchen: ' . $e->getMessage());
            $fehler[] = 'Die Entnahme konnte nicht gebucht werden.';
        }
    }
}

/* ===================================================================
   Anzeige
   =================================================================== */

// Die Suche kommt per GET aus dem Suchformular. Nach einem fehlgeschlagenen
// POST steckt sie im versteckten Feld, damit die Auswahl nicht verloren geht.
$suche = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));

$sql   = 'SELECT artikel_id, inventar_nr, ean, artikelname, menge, lagerort_text, kategorie
          FROM v_artikel WHERE menge > 0';
$werte = [];
if ($suche !== '') {
    // Ein Platzhalter, deshalb die Spalten zusammengefasst – siehe Kommentar
    // in index.php zu PDO::ATTR_EMULATE_PREPARES.
    $sql .= " AND CONCAT_WS(' ', inventar_nr, IFNULL(ean,''), artikelname, IFNULL(kiste_nr,''), "
          . "IFNULL(lagerort_text,''), IFNULL(kategorie,''), IFNULL(notiz,'')) LIKE :q";
    $werte[':q'] = '%' . $suche . '%';
}
$sql .= ' ORDER BY artikelname';

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$artikelListe = $stmt->fetchAll();

// Genau ein Treffer: direkt auswählen, dann ist das Formular nach der
// Suche sofort fertig ausgefüllt.
if ($artikelId === null && $suche !== '' && count($artikelListe) === 1) {
    $artikelId = (int) $artikelListe[0]['artikel_id'];
}

// Wie viele Artikel gäbe es ohne Filter? Für den Hinweis unter der Suche.
$gesamt = (int) $pdo->query('SELECT COUNT(*) FROM artikel WHERE menge > 0')->fetchColumn();

$gewaehlt = null;
if ($artikelId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE artikel_id = ?');
    $stmt->execute([$artikelId]);
    $gewaehlt = $stmt->fetch() ?: null;
}

$titel = 'Entnahme buchen';
$aktiv = 'entnahmen';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Entnahme buchen</h1>
  <span class="rechts">
    <a class="btn" href="zugang.php">Zugang buchen</a>
    <a class="btn" href="entnahmen.php">Protokoll</a>
  </span>
</div>

<?php if ($fehler): ?>
  <ul class="fehlerliste">
    <?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<form class="filter" method="get" action="entnahme.php">
  <div class="feld feld--breit">
    <label for="q">Artikel suchen</label>
    <input type="search" id="q" name="q" value="<?= h($suche) ?>" autofocus
           placeholder="Name, Nummer, EAN, Kiste oder Kategorie">
  </div>
  <button class="btn" type="submit">Suchen</button>
  <?php if ($suche !== ''): ?>
    <a class="btn" href="entnahme.php">Alle zeigen</a>
  <?php endif; ?>
  <span class="hinweis" style="align-self:center">
    <?= count($artikelListe) ?> von <?= $gesamt ?> Artikeln mit Bestand
  </span>
</form>

<?php if (!$artikelListe): ?>
  <p class="leer">
    <?php if ($suche !== ''): ?>
      Kein Artikel mit Bestand passt zu „<?= h($suche) ?>“.
      <a href="entnahme.php">Alle anzeigen</a>
    <?php else: ?>
      Kein Artikel hat einen Bestand über 0. Es gibt nichts zu entnehmen.
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
    <div class="karte-zahl"><?= (int) $gewaehlt['menge'] ?> <small>im Bestand</small></div>
  </div>
<?php endif; ?>

<form class="form" method="post" action="entnahme.php">
  <?= csrf_field() ?>
  <input type="hidden" name="q" value="<?= h($suche) ?>">

  <div class="zeile">
    <label for="artikel_id">Artikel<?= count($artikelListe) > 12 ? ' <span class="hinweis-inline">tippen filtert die Liste</span>' : '' ?></label>
    <?php if (count($artikelListe) > 12): ?>
      <input type="text" id="schnellfilter" placeholder="Liste eingrenzen …" autocomplete="off"
             style="margin-bottom:6px">
    <?php endif; ?>
    <select id="artikel_id" name="artikel_id" required size="<?= min(10, max(1, count($artikelListe))) ?>">
      <?php foreach ($artikelListe as $a): ?>
        <option value="<?= (int) $a['artikel_id'] ?>"
          data-suchtext="<?= h(mb_strtolower($a['inventar_nr'] . ' ' . $a['artikelname'] . ' ' . ($a['kategorie'] ?? '') . ' ' . ($a['lagerort_text'] ?? ''))) ?>"
          <?= $artikelId === (int) $a['artikel_id'] ? ' selected' : '' ?>>
          <?= h($a['inventar_nr']) ?> — <?= h($a['artikelname']) ?>
          (<?= (int) $a['menge'] ?> Stück<?= $a['lagerort_text'] ? ', ' . h($a['lagerort_text']) : '' ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="menge">Menge</label>
      <input type="number" id="menge" name="menge" min="1" step="1" required
             value="<?= h($daten['menge']) ?>">
    </div>
    <div class="zeile">
      <label for="grund">Grund</label>
      <select id="grund" name="grund" required>
        <?php foreach (ENTNAHME_GRUENDE as $wert => $label): ?>
          <option value="<?= h($wert) ?>" <?= $daten['grund'] === $wert ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="zeile">
      <label for="entnommen_am">Datum</label>
      <input type="date" id="entnommen_am" name="entnommen_am" required
             value="<?= h($daten['entnommen_am']) ?>">
    </div>
  </div>

  <div class="paar">
    <div class="zeile">
      <label for="person">Person</label>
      <input type="text" id="person" name="person" maxlength="80"
             value="<?= h($daten['person']) ?>" placeholder="wer entnimmt oder leiht">
    </div>
    <div class="zeile">
      <label for="bezug">Bezug</label>
      <input type="text" id="bezug" name="bezug" maxlength="120"
             value="<?= h($daten['bezug']) ?>" placeholder="Einsatz, Übung, Empfänger">
    </div>
  </div>

  <p class="hinweis" id="leih-hinweis">
    <strong>Verliehen</strong> ist der einzige Grund, bei dem Material zurückkommt.
    Die Buchung bleibt offen, bis du die Rückgabe im Protokoll bestätigst –
    dann wandert der Bestand automatisch zurück. Die vier anderen Gründe sind endgültig.
  </p>

  <div class="zeile">
    <label for="notiz">Notiz</label>
    <input type="text" id="notiz" name="notiz" maxlength="255" value="<?= h($daten['notiz']) ?>">
  </div>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Entnahme buchen</button>
    <a class="btn" href="index.php">Abbrechen</a>
  </div>
</form>

<script>
// Sofortfilter für die Auswahlliste. Rein optisch – gefiltert wird
// serverseitig über das Suchformular, das auch ohne JavaScript geht.
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
    // Ausgeblendete Auswahl aufheben, damit nichts Unsichtbares gebucht wird.
    if (select.selectedOptions.length && select.selectedOptions[0].hidden) {
      select.selectedIndex = -1;
    }
    select.size = Math.min(10, Math.max(1, sichtbar));
  });
})();

// Person-Feld bei einer Leihe hervorheben, dort ist es Pflicht.
(function () {
  const grund   = document.getElementById('grund');
  const person  = document.getElementById('person');
  const hinweis = document.getElementById('leih-hinweis');
  function pruefen() {
    const leihe = grund.value === '<?= GRUND_LEIHE ?>';
    person.required = leihe;
    person.placeholder = leihe ? 'Pflicht: wer hat das Material?' : 'wer entnimmt';
    hinweis.classList.toggle('hinweis--aktiv', leihe);
  }
  grund.addEventListener('change', pruefen);
  pruefen();
})();
</script>

<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
