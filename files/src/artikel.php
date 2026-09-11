<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

$id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$fehler = [];

/**
 * Schreibt die Zusatzfelder eines Artikels neu.
 * Erst alles löschen, dann die gesetzten Werte einfügen – so
 * verschwinden auch Felder, die geleert wurden oder nach einem
 * Kategoriewechsel nicht mehr gelten.
 */
function feldwerte_speichern(PDO $pdo, int $artikelId, array $werte): void
{
    $pdo->prepare('DELETE FROM artikel_feldwert WHERE artikel_id = ?')->execute([$artikelId]);
    if (!$werte) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO artikel_feldwert (artikel_id, feld_id, wert) VALUES (?,?,?)');
    foreach ($werte as $feldId => $wert) {
        $ins->execute([$artikelId, $feldId, mb_substr($wert, 0, 255)]);
    }
}

// Vorbelegung für ein neues Formular.
$daten = [
    'inventar_nr'  => '',
    // Vorbelegung aus dem Scan: ?ean=… kommt von einer unbekannten EAN.
    'ean'          => ean_saeubern((string) ($_GET['ean'] ?? '')),
    'artikelname'  => '',
    'ortart'       => 'kiste',   // 'kiste' | 'frei' | 'ohne'
    'kiste_id'     => '',
    'standort_frei'=> '',
    'kategorie_id' => '',
    'menge'        => '1',
    've_inhalt'    => '',
    've_einheit'   => '',
    'gebinde_einheit' => '',
    'lose'         => '0',
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

    $daten['ean'] = ean_saeubern($daten['ean']);
    if ($daten['ean'] !== '' && !ean_gueltig($daten['ean'])) {
        // Kein harter Fehler: hausinterne Codes haben keine gültige
        // Prüfziffer. Der Hinweis steht im Formular.
        $daten['ean_warnung'] = true;
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

    // Gebinde: entweder vollständig oder gar nicht.
    if ($daten['ve_inhalt'] !== '') {
        if (!ctype_digit($daten['ve_inhalt']) || (int) $daten['ve_inhalt'] < 1) {
            $fehler[] = 'Der Gebindeinhalt muss mindestens 1 sein.';
        }
        if ($daten['ve_einheit'] === '') {
            $fehler[] = 'Zum Gebinde fehlt die Einheit des Inhalts (z. B. Flaschen).';
        }
        if ($daten['gebinde_einheit'] === '') {
            $fehler[] = 'Zum Gebinde fehlt die Bezeichnung (z. B. Kiste).';
        }
        if (!ctype_digit($daten['lose'])) {
            $fehler[] = 'Die losen Einzelstücke müssen eine ganze Zahl ab 0 sein.';
        } elseif ((int) $daten['ve_inhalt'] >= 1 && (int) $daten['lose'] >= (int) $daten['ve_inhalt']) {
            /*
             * Früher stand hier ein Fehler. Das war zu streng: "ich habe
             * 20 Flaschen" ist eine normale Eingabe, und ob daraus eine
             * volle Kiste wird, kann die Anwendung selbst ausrechnen. Ein
             * Formular, das gültige Absichten ablehnt statt sie zu
             * verstehen, ist ein Hindernis.
             */
            $ve   = (int) $daten['ve_inhalt'];
            $lose = (int) $daten['lose'];
            $daten['menge'] = (string) ((int) $daten['menge'] + intdiv($lose, $ve));
            $daten['lose']  = (string) ($lose % $ve);
            $hinweisVE = 'Aus ' . $lose . ' losen Stücken wurden ' . intdiv($lose, $ve)
                       . ' volle ' . mehrzahl($daten['gebinde_einheit'] ?: 'Gebinde')
                       . ' gebildet, ' . ($lose % $ve) . ' bleiben lose.';
        }
    } else {
        $daten['lose'] = '0';
        $daten['ve_einheit'] = '';
        $daten['gebinde_einheit'] = '';
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

    /*
     * Zusatzfelder prüfen – aber nur die, die zur gewählten Kategorie
     * gehören. Ein Pflichtfeld der Kategorie Elektro darf das Speichern
     * eines Kleidungsstücks nicht blockieren.
     */
    $zuFelder = $pdo->query('SELECT * FROM feld ORDER BY reihenfolge, bezeichnung')->fetchAll();
    $zuSpeichern = [];
    foreach ($zuFelder as $fd) {
        if ($fd['kategorie_id'] !== null && (string) $fd['kategorie_id'] !== $daten['kategorie_id']) {
            continue;
        }
        $wert = trim((string) ($_POST['feld_' . (int) $fd['feld_id']] ?? ''));
        $meldung = feld_pruefen($fd, $wert);
        if ($meldung !== null) {
            $fehler[] = $meldung;
        } elseif ($wert !== '') {
            $zuSpeichern[(int) $fd['feld_id']] = $wert;
        }
    }

    if (!$fehler) {
        $werte = [
            $daten['ean'] !== '' ? $daten['ean'] : null,
            $daten['artikelname'],
            $daten['kiste_id']      !== '' ? (int) $daten['kiste_id'] : null,
            $daten['standort_frei'] !== '' ? $daten['standort_frei']  : null,
            $daten['kategorie_id']  !== '' ? (int) $daten['kategorie_id'] : null,
            (int) $daten['menge'],
            $daten['ve_inhalt'] !== '' ? (int) $daten['ve_inhalt'] : null,
            $daten['ve_einheit'] !== '' ? $daten['ve_einheit'] : null,
            $daten['gebinde_einheit'] !== '' ? $daten['gebinde_einheit'] : null,
            (int) $daten['lose'],
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
                       (ean, artikelname, kiste_id, standort_frei, kategorie_id, menge,
                        ve_inhalt, ve_einheit, gebinde_einheit, lose,
                        faellig_am, faellig_typ, notiz)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute($werte);
                $neueId = (int) $pdo->lastInsertId();

                $nummer = $daten['inventar_nr'] !== ''
                        ? $daten['inventar_nr']
                        : inventar_nr_aus_id($neueId);

                $pdo->prepare('UPDATE artikel SET inventar_nr = ? WHERE artikel_id = ?')
                    ->execute([$nummer, $neueId]);

                feldwerte_speichern($pdo, $neueId, $zuSpeichern);

                $pdo->commit();
                flash("Artikel $nummer angelegt." . (isset($hinweisVE) ? ' ' . $hinweisVE : ''));
            } else {
                $nummer = $daten['inventar_nr'] !== ''
                        ? $daten['inventar_nr']
                        : inventar_nr_aus_id($id);

                $stmt = $pdo->prepare(
                    'UPDATE artikel SET
                        ean = ?, artikelname = ?, kiste_id = ?, standort_frei = ?, kategorie_id = ?,
                        menge = ?, ve_inhalt = ?, ve_einheit = ?, gebinde_einheit = ?, lose = ?,
                        faellig_am = ?, faellig_typ = ?, notiz = ?, inventar_nr = ?
                     WHERE artikel_id = ?'
                );
                $stmt->execute([...$werte, $nummer, $id]);
                feldwerte_speichern($pdo, $id, $zuSpeichern);
                flash('Änderungen gespeichert.' . (isset($hinweisVE) ? ' ' . $hinweisVE : ''));
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
        'ean'          => (string) ($vorhanden['ean'] ?? ''),
        'artikelname'  => (string) $vorhanden['artikelname'],
        'ortart'       => $vorhanden['kiste_id'] !== null ? 'kiste'
                        : ($vorhanden['standort_frei'] !== null ? 'frei' : 'ohne'),
        'kiste_id'     => (string) ($vorhanden['kiste_id'] ?? ''),
        'standort_frei'=> (string) ($vorhanden['standort_frei'] ?? ''),
        'kategorie_id' => (string) ($vorhanden['kategorie_id'] ?? ''),
        'menge'        => (string) $vorhanden['menge'],
        've_inhalt'    => (string) ($vorhanden['ve_inhalt'] ?? ''),
        've_einheit'   => (string) ($vorhanden['ve_einheit'] ?? ''),
        'gebinde_einheit' => (string) ($vorhanden['gebinde_einheit'] ?? ''),
        'lose'         => (string) ($vorhanden['lose'] ?? '0'),
        'faellig_am'   => (string) ($vorhanden['faellig_am'] ?? ''),
        'faellig_typ'  => (string) ($vorhanden['faellig_typ'] ?? ''),
        'notiz'        => (string) ($vorhanden['notiz'] ?? ''),
    ];
}

$kisten = $pdo->query('SELECT kiste_id, kiste_nr, lager, regal, fach FROM kiste ORDER BY ' . ORDER_LAGERPLATZ)->fetchAll();
$kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();

/*
 * Benutzerdefinierte Felder.
 *
 * Es werden ALLE Definitionen geladen und im Formular gerendert; per
 * JavaScript wird nur eingeblendet, was zur gewählten Kategorie passt.
 * Grund: die Kategorie kann im Formular gewechselt werden, und ein
 * Nachladen bei jedem Wechsel würde eingegebene Werte verlieren.
 * Gespeichert wird trotzdem nur, was serverseitig zur Kategorie gehört.
 */
$felder = $pdo->query(
    'SELECT * FROM feld ORDER BY kategorie_id IS NOT NULL, reihenfolge, bezeichnung'
)->fetchAll();

// Bereits erfasste Werte des Artikels.
$feldwerte = [];
if ($id !== null) {
    $stmt = $pdo->prepare('SELECT feld_id, wert FROM artikel_feldwert WHERE artikel_id = ?');
    $stmt->execute([$id]);
    $feldwerte = array_column($stmt->fetchAll(), 'wert', 'feld_id');
}
// Nach einem fehlgeschlagenen POST die Eingaben zurückspielen.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($felder as $fd) {
        $key = 'feld_' . (int) $fd['feld_id'];
        if (isset($_POST[$key])) {
            $feldwerte[(int) $fd['feld_id']] = trim((string) $_POST[$key]);
        }
    }
}

/** Gehört das Feld zur gewählten Kategorie? NULL-Kategorie gilt immer. */
function feld_passt(array $feld, string $katId): bool
{
    return $feld['kategorie_id'] === null
        || (string) $feld['kategorie_id'] === $katId;
}

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

  <div class="zeile">
    <label for="ean">EAN / Barcode</label>
    <input type="text" id="ean" name="ean" maxlength="14" inputmode="numeric"
           value="<?= h($daten['ean']) ?>" placeholder="scannen oder eintippen">
    <p class="hinweis">
      Ermöglicht Suche und Buchung per Scanner. Nicht eindeutig – derselbe Artikel
      darf in mehreren Kisten liegen und trägt dann überall dieselbe EAN.
      <?php if ($daten['ean'] !== '' && !ean_gueltig($daten['ean'])): ?>
        <strong>Die Prüfziffer stimmt nicht.</strong> Bei einem hausinternen Code ist das in Ordnung,
        bei einer abgetippten Herstellernummer meist ein Zahlendreher.
      <?php endif; ?>
    </p>
  </div>

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
      <label for="menge">Bestand<span class="hinweis-inline" id="menge-einheit"></span></label>
      <input type="number" id="menge" name="menge" min="0" step="1" value="<?= h($daten['menge']) ?>">
    </div>
  </div>

  <fieldset class="gebinde">
    <legend>Verpackungseinheit</legend>
    <p class="hinweis" style="margin-top:0">
      Für Ware, die in Gebinden lagert – etwa 20 Flaschen je Kiste. Der Bestand oben
      zählt dann volle Gebinde, angebrochene Stücke stehen separat.
      Feld leer lassen, wenn der Artikel einzeln gezählt wird.
    </p>
    <div class="paar">
      <div class="zeile">
        <label for="ve_inhalt">Inhalt je Gebinde</label>
        <input type="number" id="ve_inhalt" name="ve_inhalt" min="1" step="1"
               value="<?= h($daten['ve_inhalt']) ?>" placeholder="20">
      </div>
      <div class="zeile">
        <label for="ve_einheit">Einheit des Inhalts</label>
        <input type="text" id="ve_einheit" name="ve_einheit" maxlength="30"
               value="<?= h($daten['ve_einheit']) ?>" placeholder="Flaschen"
               list="ve-vorschlaege">
        <datalist id="ve-vorschlaege">
          <option value="Flaschen"><option value="Stück"><option value="Beutel">
          <option value="Packungen"><option value="Paar"><option value="Meter">
        </datalist>
      </div>
      <div class="zeile">
        <label for="gebinde_einheit">Gebinde heißt</label>
        <input type="text" id="gebinde_einheit" name="gebinde_einheit" maxlength="30"
               value="<?= h($daten['gebinde_einheit']) ?>" placeholder="Kiste"
               list="geb-vorschlaege">
        <datalist id="geb-vorschlaege">
          <option value="Kiste"><option value="Karton"><option value="Palette">
          <option value="Sack"><option value="Rolle"><option value="Packung">
        </datalist>
      </div>
      <div class="zeile">
        <label for="lose">Lose Einzelstücke</label>
        <input type="number" id="lose" name="lose" min="0" step="1" value="<?= h($daten['lose']) ?>">
      </div>
    </div>
    <p class="hinweis" id="gebinde-rechnung"></p>
  </fieldset>

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

  <?php if ($felder): ?>
    <fieldset class="zusatzfelder">
      <legend>Zusatzangaben</legend>
      <?php foreach ($felder as $fd):
          $fid  = (int) $fd['feld_id'];
          $wert = (string) ($feldwerte[$fid] ?? '');
          $passt = feld_passt($fd, $daten['kategorie_id']);
      ?>
        <div class="zeile zusatzfeld" data-kategorie="<?= h((string) ($fd['kategorie_id'] ?? '')) ?>"
             <?= $passt ? '' : 'hidden' ?>>
          <label for="feld_<?= $fid ?>">
            <?= h($fd['bezeichnung']) ?>
            <?php if ($fd['einheit']): ?><span class="hinweis-inline">in <?= h($fd['einheit']) ?></span><?php endif; ?>
            <?php if ($fd['pflicht']): ?><span class="pflicht-marke">Pflicht</span><?php endif; ?>
            <?php if ($fd['kategorie_id'] === null): ?><span class="hinweis-inline">alle Artikel</span><?php endif; ?>
          </label>
          <?= feld_eingabe($fd, $wert) ?>
          <?php if ($fd['hinweis']): ?><p class="hinweis"><?= h($fd['hinweis']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <p class="hinweis" id="zusatz-leer" hidden>
        Für diese Kategorie sind keine Zusatzfelder definiert.
        <?php if (darf('admin')): ?><a href="felder.php?neu=1">Feld anlegen</a><?php endif; ?>
      </p>
    </fieldset>
  <?php endif; ?>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Speichern</button>
    <a class="btn" href="index.php">Abbrechen</a>
    <?php if ($id !== null): ?>
      <a class="btn weg" href="zugang.php?artikel=<?= $id ?>">Zugang buchen</a>
      <a class="btn" href="entnahme.php?artikel=<?= $id ?>">Entnahme buchen</a>
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
// Zeigt die Gebinderechnung live an, damit sofort sichtbar ist, was
// eingetragene Zahlen bedeuten.
(function () {
  const inh = document.getElementById('ve_inhalt');
  const eInh = document.getElementById('ve_einheit');
  const eGeb = document.getElementById('gebinde_einheit');
  const lose = document.getElementById('lose');
  const menge = document.getElementById('menge');
  const aus = document.getElementById('gebinde-rechnung');
  const lbl = document.getElementById('menge-einheit');
  if (!inh || !aus) return;
  function rechnen() {
    const ve = parseInt(inh.value, 10);
    if (!ve || ve < 1) {
      aus.textContent = '';
      lbl.textContent = ' in Stück';
      return;
    }
    const m = parseInt(menge.value, 10) || 0;
    const l = parseInt(lose.value, 10) || 0;
    const gEinheit = eGeb.value || 'Gebinde';
    const iEinheit = eInh.value || 'Stück';
    aus.textContent = `${m} × ${gEinheit} à ${ve} ${iEinheit}`
      + (l ? ` + ${l} lose` : '') + ` = ${m * ve + l} ${iEinheit} gesamt`;
    lbl.textContent = ` in ${gEinheit}`;
  }
  [inh, eInh, eGeb, lose, menge].forEach(e => e && e.addEventListener('input', rechnen));
  rechnen();
})();

// Zusatzfelder an die gewählte Kategorie anpassen. Felder ohne
// Kategoriebindung (data-kategorie="") gelten immer.
(function () {
  const kat = document.getElementById('kategorie_id');
  const felder = document.querySelectorAll('.zusatzfeld');
  const leer = document.getElementById('zusatz-leer');
  if (!kat || !felder.length) return;
  function zeigen() {
    let sichtbar = 0;
    felder.forEach(f => {
      const k = f.dataset.kategorie;
      const passt = k === '' || k === kat.value;
      f.hidden = !passt;
      // Ausgeblendete Pflichtfelder dürfen das Absenden nicht blockieren.
      f.querySelectorAll('input, select').forEach(e => {
        if (e.dataset.pflicht === undefined && e.required) e.dataset.pflicht = '1';
        e.required = passt && e.dataset.pflicht === '1';
      });
      if (passt) sichtbar++;
    });
    if (leer) leer.hidden = sichtbar > 0;
  }
  kat.addEventListener('change', zeigen);
  zeigen();
})();

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
