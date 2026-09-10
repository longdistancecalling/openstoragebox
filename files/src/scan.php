<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Buchen per Scan.
 *
 * Erkannt werden drei Eingaben:
 *   K:K001      eigener Code einer Kiste  -> Inhaltsliste
 *   A:A-00042   eigener Code eines Artikels
 *   4006381…    EAN eines Artikels
 *
 * Der eigene Code kommt entweder aus dem Eingabefeld (Handscanner) oder
 * als Parameter ?c=… aus einer URL – dann hat jemand den QR-Code mit
 * der Handykamera gescannt und die Seite direkt geöffnet.
 *
 * Ein USB-Handscanner verhält sich wie eine Tastatur: er tippt und
 * schickt Enter. Deshalb genügt ein Textfeld mit Autofokus, es braucht
 * keine Scanner-Bibliothek und keine Kamerarechte.
 *
 * Der Ablauf ist zweistufig: scannen zeigt an, gebucht wird erst mit
 * dem zweiten Klick. Ein versehentlicher Scan verändert nichts.
 */

$ich    = benutzer();
$fehler = [];
$letzte = null;

/* ===================================================================
   Eingabe einlesen und deuten
   =================================================================== */

/**
 * Zerlegt eine Eingabe in Art und Kennung.
 * Versteht auch eine vollständige URL, falls jemand den gescannten
 * Text aus der Kamera-App einfügt.
 *
 * @return array{art:string, wert:string}  art: kiste|artikel|ean|leer
 */
function code_deuten(string $roh): array
{
    $roh = trim($roh);
    if ($roh === '') {
        return ['art' => 'leer', 'wert' => ''];
    }

    // Vollständige URL: den Parameter c herausziehen.
    if (preg_match('#^https?://#i', $roh)) {
        $teile = parse_url($roh);
        parse_str($teile['query'] ?? '', $q);
        $roh = trim((string) ($q['c'] ?? ''));
        if ($roh === '') {
            return ['art' => 'leer', 'wert' => ''];
        }
    }

    if (preg_match('/^K:(.+)$/i', $roh, $m)) {
        return ['art' => 'kiste', 'wert' => trim($m[1])];
    }
    if (preg_match('/^A:(.+)$/i', $roh, $m)) {
        return ['art' => 'artikel', 'wert' => trim($m[1])];
    }

    // Alles andere als EAN behandeln – nur Ziffern übrig lassen.
    $ziffern = ean_saeubern($roh);
    return $ziffern !== ''
        ? ['art' => 'ean', 'wert' => $ziffern]
        : ['art' => 'leer', 'wert' => ''];
}

$eingabe = (string) ($_POST['code'] ?? $_GET['c'] ?? $_GET['ean'] ?? '');
$code    = code_deuten($eingabe);

/* ===================================================================
   Buchung
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    csrf_check();

    $artikelId = ctype_digit((string) ($_POST['artikel_id'] ?? '')) ? (int) $_POST['artikel_id'] : 0;
    $richtung  = (string) ($_POST['richtung'] ?? '');
    $menge     = (string) ($_POST['menge'] ?? '1');
    $grund     = (string) ($_POST['grund'] ?? '');
    $einheitIn = (string) ($_POST['einheit'] ?? 'gebinde');

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
            // Transaktion mit Zeilensperre: Bestand prüfen, ändern und
            // protokollieren gehören zusammen.
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

            $einheit = empty($art['ve_inhalt']) ? 'einzeln' : $einheitIn;
            [$neueMenge, $neueLose] = bestand_rechnen($art, $menge, $einheit, $richtung === 'zugang');

            $pdo->prepare('UPDATE artikel SET menge = ?, lose = ? WHERE artikel_id = ?')
                ->execute([$neueMenge, $neueLose, $artikelId]);

            if ($richtung === 'abgang') {
                $pdo->prepare(
                    'INSERT INTO entnahme (artikel_id, menge, einheit, grund, entnommen_am,
                                           person, bezug, notiz, erfasst_von)
                     VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)'
                )->execute([
                    $artikelId, $menge, $einheit, $grund, $ich['anzeigename'],
                    trim((string) ($_POST['bezug'] ?? '')) ?: null,
                    'per Scan gebucht', (int) $ich['benutzer_id'],
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO zugang (artikel_id, menge, einheit, grund, datum,
                                         person, bezug, notiz, erfasst_von)
                     VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)'
                )->execute([
                    $artikelId, $menge, $einheit, $grund, $ich['anzeigename'],
                    trim((string) ($_POST['bezug'] ?? '')) ?: null,
                    'per Scan gebucht', (int) $ich['benutzer_id'],
                ]);
            }

            $pdo->commit();

            $art['gesamt_einzeln'] = $neueMenge * (int) ($art['ve_inhalt'] ?? 1) + $neueLose;
            $art['menge'] = $neueMenge;
            $art['lose']  = $neueLose;

            $letzte = [
                'text'     => ($richtung === 'zugang' ? '+' : '−') . ' '
                            . einheit_text($art, $einheit, $menge) . ' ' . $art['artikelname'],
                'stand'    => bestand_text($art + ['ve_inhalt' => $art['ve_inhalt']]),
                'richtung' => $richtung,
            ];
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
   Nachschlagen
   =================================================================== */
$treffer  = [];
$kiste    = null;
$ohneCode = [];
$eanUngueltig = false;

if ($code['art'] === 'kiste') {
    $stmt = $pdo->prepare('SELECT * FROM kiste WHERE kiste_nr = ?');
    $stmt->execute([$code['wert']]);
    $kiste = $stmt->fetch() ?: null;

    if ($kiste) {
        $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE kiste_id = ? ORDER BY artikelname');
        $stmt->execute([(int) $kiste['kiste_id']]);
        $treffer = $stmt->fetchAll();
    }

} elseif ($code['art'] === 'artikel') {
    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE inventar_nr = ?');
    $stmt->execute([$code['wert']]);
    $treffer = $stmt->fetchAll();

} elseif ($code['art'] === 'ean') {
    $eanUngueltig = !ean_gueltig($code['wert']);
    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE ean = ? ORDER BY ' . ORDER_LAGERORT);
    $stmt->execute([$code['wert']]);
    $treffer = $stmt->fetchAll();

    if (!$treffer) {
        // Unbekannte EAN ist der Normalfall beim ersten Scan.
        $ohneCode = $pdo->query(
            'SELECT artikel_id, inventar_nr, artikelname, menge, lagerort_text
             FROM v_artikel WHERE ean IS NULL ORDER BY artikelname'
        )->fetchAll();
    }
}

/* ===================================================================
   EAN zuordnen
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'zuordnen') {
    csrf_check();
    $artikelId = ctype_digit((string) ($_POST['artikel_id'] ?? '')) ? (int) $_POST['artikel_id'] : 0;
    if ($code['art'] !== 'ean' || $artikelId === 0) {
        $fehler[] = 'EAN oder Artikel fehlt.';
    } else {
        $pdo->prepare('UPDATE artikel SET ean = ? WHERE artikel_id = ?')
            ->execute([$code['wert'], $artikelId]);
        flash('EAN ' . $code['wert'] . ' zugeordnet.');
        redirect('scan.php?c=' . rawurlencode($code['wert']));
    }
}

/** Zeigt die Buchungsknöpfe für einen Artikel. */
function buchungsformular(array $a, string $code): void
{
    $gebinde = (int) $a['hat_gebinde'] === 1;
    ?>
    <div class="scan-aktionen">
      <?php foreach ([
          ['abgang', '− Entnehmen', 'btn--gefahr', ENTNAHME_GRUENDE],
          ['zugang', '+ Hinzufügen', 'btn--primaer', ZUGANG_GRUENDE],
      ] as [$richtung, $label, $klasse, $gruende]): ?>
        <form method="post" action="scan.php" class="scan-form">
          <?= csrf_field() ?>
          <input type="hidden" name="aktion" value="buchen">
          <input type="hidden" name="code" value="<?= h($code) ?>">
          <input type="hidden" name="artikel_id" value="<?= (int) $a['artikel_id'] ?>">
          <input type="hidden" name="richtung" value="<?= h($richtung) ?>">
          <div class="scan-zeile">
            <input type="number" name="menge" value="1" min="1" step="1"
                   aria-label="Menge <?= h($label) ?>">
            <?php if ($gebinde): ?>
              <select name="einheit" aria-label="Einheit">
                <option value="gebinde"><?= h(mehrzahl($a['gebinde_einheit'] ?: 'Gebinde')) ?></option>
                <option value="einzeln"><?= h($a['ve_einheit'] ?: 'Stück') ?></option>
              </select>
            <?php endif; ?>
            <select name="grund" aria-label="Grund">
              <?php foreach ($gruende as $w => $l):
                  if ($richtung === 'abgang' && $w === GRUND_LEIHE) { continue; } ?>
                <option value="<?= h($w) ?>"><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn <?= h($klasse) ?>" type="submit"
              <?= $richtung === 'abgang' && (int) $a['gesamt_einzeln'] === 0 ? 'disabled' : '' ?>>
              <?= h($label) ?>
            </button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
    <?php
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
  <p class="flash">
    <strong><?= h($letzte['text']) ?></strong> gebucht · neuer Bestand: <?= h($letzte['stand']) ?>
  </p>
<?php endif; ?>

<?php if ($fehler): ?>
  <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<form class="scanfeld" method="get" action="scan.php">
  <label for="c">Code scannen oder eingeben</label>
  <input type="text" id="c" name="c" value="<?= h($eingabe) ?>" autofocus
         autocomplete="off" placeholder="QR-Code, Kistennummer oder EAN">
  <button class="btn btn--primaer" type="submit">Suchen</button>
</form>

<?php if ($code['art'] === 'ean' && $eanUngueltig): ?>
  <p class="flash flash--fehler">
    Die Prüfziffer von <?= h($code['wert']) ?> stimmt nicht. Beim Abtippen passiert das
    leicht. Buchen lässt sich trotzdem, falls es ein hausinterner Code ist.
  </p>
<?php endif; ?>

<?php /* ---------- Kiste ---------- */ ?>
<?php if ($code['art'] === 'kiste'): ?>

  <?php if (!$kiste): ?>
    <p class="leer">
      Es gibt keine Kiste mit der Nummer <strong><?= h($code['wert']) ?></strong>.
      <a href="kiste.php">Kiste anlegen</a>
    </p>
  <?php else: ?>
    <div class="scan-karte">
      <div class="scan-kopf">
        <div>
          <span class="platz-kiste"><?= h($kiste['kiste_nr']) ?></span>
          <strong class="scan-name"><?= h($kiste['lager'] . ' / ' . $kiste['regal'] . ' / ' . $kiste['fach']) ?></strong>
          <?php if ($kiste['notiz']): ?><div class="notiz"><?= h($kiste['notiz']) ?></div><?php endif; ?>
        </div>
        <div class="scan-bestand">
          <span class="scan-zahl"><?= count($treffer) ?></span>
          <span class="scan-label">Positionen</span>
        </div>
      </div>
      <p class="hinweis">
        <a href="etikett.php?id=<?= (int) $kiste['kiste_id'] ?>">Etikett drucken</a> ·
        <a href="kiste.php?id=<?= (int) $kiste['kiste_id'] ?>">Kiste bearbeiten</a>
      </p>
    </div>

    <?php if (!$treffer): ?>
      <p class="leer">Diese Kiste ist leer.</p>
    <?php else: ?>
      <h2 class="abschnitt">Inhalt – zum Buchen auswählen</h2>
      <?php foreach ($treffer as $a): ?>
        <div class="scan-karte scan-karte--kompakt">
          <div class="scan-kopf">
            <div>
              <span class="invnr"><?= h($a['inventar_nr']) ?></span>
              <strong class="scan-name"><?= h($a['artikelname']) ?></strong>
              <?php if ($a['zusatzfelder']): ?>
                <div class="zusatz"><?= h($a['zusatzfelder']) ?></div>
              <?php endif; ?>
            </div>
            <div class="scan-bestand">
              <span class="scan-zahl"><?= (int) ($a['hat_gebinde'] ? $a['menge'] : $a['gesamt_einzeln']) ?></span>
              <span class="scan-label"><?= h(bestand_text($a)) ?></span>
            </div>
          </div>
          <?php buchungsformular($a, $eingabe); ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php /* ---------- Artikel oder EAN ---------- */ ?>
<?php elseif ($treffer): ?>

  <?php foreach ($treffer as $a):
      $leihe = (int) $a['verliehen_offen'];
      $tage  = $a['tage_bis_faellig'] === null ? null : (int) $a['tage_bis_faellig'];
      $stufe = faellig_stufe($tage);
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
            <div style="margin-top:6px">
              <span class="marke marke--<?= $stufe === 'faellig' ? 'rot' : ($stufe === 'bald' ? 'amber' : 'gruen') ?>">
                <?= h(faellig_label($a['faellig_typ'])) ?> <?= h(datum_de($a['faellig_am'])) ?>
              </span>
            </div>
          <?php endif; ?>
        </div>
        <div class="scan-bestand">
          <span class="scan-zahl"><?= (int) ($a['hat_gebinde'] ? $a['menge'] : $a['gesamt_einzeln']) ?></span>
          <span class="scan-label"><?= h(bestand_text($a)) ?></span>
          <?php if ($leihe > 0): ?><span class="leihe"><?= $leihe ?> verliehen</span><?php endif; ?>
        </div>
      </div>

      <?php buchungsformular($a, $eingabe); ?>

      <p class="hinweis">
        Gebucht wird auf deinen Namen und das heutige Datum. Für Leihen, abweichende
        Daten oder eine Notiz nutze
        <a href="entnahme.php?artikel=<?= (int) $a['artikel_id'] ?>">Entnahme buchen</a>.
      </p>
    </div>
  <?php endforeach; ?>

<?php /* ---------- Artikelcode ohne Treffer ---------- */ ?>
<?php elseif ($code['art'] === 'artikel'): ?>
  <p class="leer">
    Es gibt keinen Artikel mit der Inventarnummer <strong><?= h($code['wert']) ?></strong>.
  </p>

<?php /* ---------- Unbekannte EAN ---------- */ ?>
<?php elseif ($code['art'] === 'ean'): ?>
  <div class="scan-karte">
    <h2 class="scan-name">EAN <?= h(ean_anzeigen($code['wert'])) ?> ist unbekannt</h2>
    <p class="hinweis">
      Ordne sie einem vorhandenen Artikel zu – dann funktioniert der Scan ab sofort –
      oder lege den Artikel neu an.
    </p>
    <?php if ($ohneCode): ?>
      <form method="post" action="scan.php" class="form" style="max-width:none;border:0;padding:0">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="zuordnen">
        <input type="hidden" name="code" value="<?= h($eingabe) ?>">
        <div class="zeile">
          <label for="artikel_id">Artikel ohne EAN</label>
          <select id="artikel_id" name="artikel_id" required size="<?= min(8, max(2, count($ohneCode))) ?>">
            <?php foreach ($ohneCode as $a): ?>
              <option value="<?= (int) $a['artikel_id'] ?>">
                <?= h($a['inventar_nr']) ?> — <?= h($a['artikelname']) ?>
                (<?= (int) $a['menge'] ?><?= $a['lagerort_text'] ? ', ' . h($a['lagerort_text']) : '' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="knoepfe">
          <button class="btn btn--primaer" type="submit">EAN zuordnen</button>
          <a class="btn" href="artikel.php?ean=<?= urlencode($code['wert']) ?>">Neuen Artikel anlegen</a>
        </div>
      </form>
    <?php else: ?>
      <div class="knoepfe">
        <a class="btn btn--primaer" href="artikel.php?ean=<?= urlencode($code['wert']) ?>">Neuen Artikel anlegen</a>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <p class="leer">
    Code scannen oder eintippen. Ein USB-Handscanner funktioniert direkt – er verhält
    sich wie eine Tastatur. Erkannt werden die eigenen QR-Codes von Kisten
    (<code>K:K001</code>) und Artikeln (<code>A:A-00042</code>) sowie Hersteller-EANs.
  </p>
<?php endif; ?>

<script>
// Nach jeder Buchung zurück ins Eingabefeld und Inhalt markieren, damit
// der nächste Scan ihn einfach überschreibt.
(function () {
  const feld = document.getElementById('c');
  if (feld) { feld.focus(); feld.select(); }
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
