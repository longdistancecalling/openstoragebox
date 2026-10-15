<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

$artikelId = isset($_GET['artikel']) && ctype_digit((string) $_GET['artikel'])
           ? (int) $_GET['artikel'] : null;
$fehler = [];

$daten = [
    'menge'        => '1',
    'einheit'      => 'gebinde',
    'grund'        => 'einsatz',
    'entnommen_am' => date('Y-m-d'),
    // Wer bucht, ist angemeldet – der Name steht also schon fest.
    // Überschreibbar, weil man auch für jemand anderen buchen kann.
    'person'       => benutzer()['anzeigename'],
    'bezug'        => '',
    'notiz'        => '',
    'mhd'          => '',   // gewählte Charge bei Artikeln mit MHD-Chargen
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

            $stmt = $pdo->prepare(
                'SELECT artikelname, menge, lose, ve_inhalt, ve_einheit, gebinde_einheit, mit_chargen
                 FROM artikel WHERE artikel_id = ? FOR UPDATE'
            );
            $stmt->execute([$artikelId]);
            $art = $stmt->fetch();

            if (!$art) {
                throw new RuntimeException('Dieser Artikel existiert nicht.');
            }

            $menge   = (int) $daten['menge'];
            $einheit = empty($art['ve_inhalt']) ? 'einzeln' : $daten['einheit'];

            // Rechnet Gebinde und lose Stücke aus und wirft, wenn der
            // Bestand nicht reicht.
            [$neueMenge, $neueLose] = bestand_rechnen($art, $menge, $einheit, false);

            $pdo->prepare('UPDATE artikel SET menge = ?, lose = ? WHERE artikel_id = ?')
                ->execute([$neueMenge, $neueLose, $artikelId]);

            // MHD-Chargen: entnommen wird genau aus der gewählten Charge.
            $mhd = mhd_aus_eingabe($daten['mhd']);
            if (!empty($art['mit_chargen']) && $mhd === null) {
                throw new RuntimeException('Bitte die Charge (MHD) auswählen, aus der entnommen wird.');
            }
            $mhd = chargen_mitbuchen($pdo, $art, $artikelId, stueckzahl($art, $menge, $einheit), false, $mhd);

            $pdo->prepare(
                'INSERT INTO entnahme (artikel_id, menge, einheit, mhd, grund, entnommen_am, person, bezug, notiz, erfasst_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $artikelId,
                $menge,
                $einheit,
                $mhd,
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

            $text = einheit_text($art, $einheit, $menge) . ' ' . $art['artikelname']
                  . ($mhd !== null ? ' mit MHD ' . datum_de($mhd) : '')
                  . ' entnommen (' . grund_label($daten['grund']) . ').';
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

$sql   = 'SELECT * FROM v_artikel WHERE gesamt_einzeln > 0';
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

/*
 * Chargen aller angezeigten Chargen-Artikel, damit die Auswahl beim
 * Wechsel des Artikels im Browser umgebaut werden kann:
 * artikel_id => [[mhd, Beschriftung], …], älteste zuerst.
 */
$chargenJe = [];
$nachId = array_column($artikelListe, null, 'artikel_id');
$stmt = $pdo->query(
    'SELECT c.artikel_id, c.mhd, c.menge
     FROM charge c JOIN artikel a ON a.artikel_id = c.artikel_id
     WHERE a.mit_chargen = 1 ORDER BY c.artikel_id, c.mhd'
);
foreach ($stmt as $c) {
    $a = $nachId[$c['artikel_id']] ?? null;
    if ($a) {
        $chargenJe[(int) $c['artikel_id']][] =
            [$c['mhd'], 'MHD ' . datum_de($c['mhd']) . ' – ' . einzeln_text($a, (int) $c['menge'])];
    }
}
$gewaehltChargen = $gewaehlt && (int) $gewaehlt['mit_chargen'] === 1
                 ? chargen_laden($pdo, (int) $gewaehlt['artikel_id']) : [];

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
    <div class="karte-zahl">
      <?= $gewaehlt['hat_gebinde'] ? (int) $gewaehlt['menge'] : (int) $gewaehlt['gesamt_einzeln'] ?>
      <small><?= $gewaehlt['hat_gebinde']
          ? h(mehrzahl($gewaehlt['gebinde_einheit'] ?: 'Gebinde')) . ' · ' . (int) $gewaehlt['gesamt_einzeln'] . ' ' . h($gewaehlt['ve_einheit'] ?: 'Stück')
          : 'im Bestand' ?></small>
    </div>
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
      <input type="number" id="menge" name="menge" min="1" step="1" required
             value="<?= h($daten['menge']) ?>">
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
    <div class="zeile" id="feld-charge"<?= $gewaehltChargen ? '' : ' hidden' ?>>
      <label for="mhd">Charge <span class="hinweis-inline">älteste vorausgewählt</span></label>
      <?php if ($gewaehltChargen): ?>
        <?= charge_auswahl($gewaehlt, $gewaehltChargen, 'mhd', $daten['mhd']) ?>
      <?php else: ?>
        <select id="mhd" name="mhd"></select>
      <?php endif; ?>
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
    Bei <strong>Verliehen</strong> und <strong>Einsatz</strong> kann Material zurückkommen,
    auch in Teilen – die Rückgabe buchst du später unter „Bewegungen“, dann wandert der
    Bestand zurück. Eine Leihe bleibt offen, bis alles zurück ist; beim Einsatz gilt, was
    nicht zurückkommt, als verbraucht. Verschleiß, Defekt und Abgelaufen sind endgültig.
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

/*
 * Einheit und Charge an den gewählten Artikel anpassen – wie in
 * zugang.php. Ohne das bliebe die Auswahl beim zuerst angezeigten
 * Artikel stehen, wenn man in der Liste einen anderen anklickt.
 */
(function () {
  const CHARGEN = <?= json_encode($chargenJe, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  const sel        = document.getElementById('artikel_id');
  const zeileEinh  = document.getElementById('feld-einheit');
  const eSel       = document.getElementById('einheit');
  const zeileCharge = document.getElementById('feld-charge');
  const cSel       = document.getElementById('mhd');
  if (!sel) return;
  sel.addEventListener('change', () => {
    const o = sel.selectedOptions[0];
    if (!o) return;
    const g = o.dataset.gebinde || '', e = o.dataset.einzeln || '';
    zeileEinh.hidden = g === '' && e === '';
    if (!zeileEinh.hidden) {
      eSel.options[0].textContent = g || 'Gebinde';
      eSel.options[1].textContent = e || 'Einzelstücke';
    }
    const liste = CHARGEN[o.value] || [];
    cSel.innerHTML = '';
    liste.forEach(([mhd, text], i) => { cSel.add(new Option(text, mhd, i === 0, i === 0)); });
    zeileCharge.hidden = liste.length === 0;
    // Ein verstecktes Pflichtfeld würde das Absenden stumm blockieren.
    cSel.required = liste.length > 0;
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
