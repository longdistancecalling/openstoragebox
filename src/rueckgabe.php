<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Rückgabe zu einer Entnahme buchen – ganz oder in Teilen.
 *
 * Zu einer Leihe oder einer Einsatzentnahme können beliebig viele
 * Rückgaben gehören. Gerechnet wird in Einzelstücken: aus einer
 * ausgegebenen Kiste können auch einzelne Flaschen zurückkommen.
 *
 *   ?id=…   die Entnahme (Belegnummer ohne "E-")
 */

$eid = ctype_digit((string) ($_POST['entnahme_id'] ?? $_GET['id'] ?? ''))
     ? (int) ($_POST['entnahme_id'] ?? $_GET['id']) : 0;

/** Entnahme samt Rückgabestand und Artikeldaten. */
function entnahme_laden(PDO $pdo, int $eid): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.*, a.artikelname, a.inventar_nr, a.ve_inhalt, a.ve_einheit,
                a.gebinde_einheit, a.mit_chargen, b.anzeigename AS gebucht_von
         FROM v_entnahme e
         JOIN artikel a ON a.artikel_id = e.artikel_id
         LEFT JOIN benutzer b ON b.benutzer_id = e.erfasst_von
         WHERE e.entnahme_id = ?'
    );
    $stmt->execute([$eid]);
    return $stmt->fetch() ?: null;
}

$e = entnahme_laden($pdo, $eid);
if (!$e || !rueckgabe_moeglich($e['grund'])) {
    flash('Zu dieser Buchung kann keine Rückgabe gebucht werden.', 'fehler');
    redirect('entnahmen.php');
}

$ve      = (int) ($e['ve_inhalt'] ?? 0);
$offen   = (int) $e['offen_einzeln'];
$fehler  = [];

// Vorschlag: alles, was noch offen ist – in Gebinden, wenn so
// ausgegeben wurde und es glatt aufgeht.
$alsGebinde = $ve > 1 && $e['einheit'] === 'gebinde' && $offen % $ve === 0;
$daten = [
    'menge'   => (string) ($alsGebinde ? intdiv($offen, $ve) : $offen),
    'einheit' => $alsGebinde ? 'gebinde' : 'einzeln',
    'datum'   => date('Y-m-d'),
    'notiz'   => '',
    // MHD-Chargen: zurück in die Charge, aus der entnommen wurde. Nur
    // wenn die Entnahme keine kennt (vor den Chargen gebucht), wird
    // das MHD abgefragt.
    'mhd'     => '',
];
$mhdBekannt = $e['mhd'] !== null;
$mhdNoetig  = (int) $e['mit_chargen'] === 1 && !$mhdBekannt;

/* ===================================================================
   POST
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (array_keys($daten) as $feld) {
        $daten[$feld] = trim((string) ($_POST[$feld] ?? ''));
    }

    if (!ctype_digit($daten['menge']) || (int) $daten['menge'] < 1) {
        $fehler[] = 'Die Menge muss mindestens 1 sein.';
    }
    if (!in_array($daten['einheit'], ['gebinde', 'einzeln'], true)) {
        $fehler[] = 'Unbekannte Einheit.';
    }
    if (!DateTimeImmutable::createFromFormat('Y-m-d', $daten['datum'])) {
        $fehler[] = 'Das Rückgabedatum ist ungültig.';
    } elseif ($daten['datum'] < $e['entnommen_am']) {
        $fehler[] = 'Die Rückgabe kann nicht vor der Entnahme am ' . datum_de($e['entnommen_am']) . ' liegen.';
    }

    if (!$fehler) {
        try {
            /*
             * Transaktion mit zwei Sperren: die Entnahmezeile, damit zwei
             * gleichzeitige Rückgaben nicht beide denselben offenen Rest
             * sehen, und die Artikelzeile wie bei jeder Bestandsänderung.
             */
            $pdo->beginTransaction();

            $pdo->prepare('SELECT entnahme_id FROM entnahme WHERE entnahme_id = ? FOR UPDATE')
                ->execute([$eid]);
            $stmt = $pdo->prepare(
                'SELECT artikelname, menge, lose, ve_inhalt, ve_einheit, gebinde_einheit, mit_chargen
                 FROM artikel WHERE artikel_id = ? FOR UPDATE'
            );
            $stmt->execute([(int) $e['artikel_id']]);
            $art = $stmt->fetch();

            // Offenen Rest erst nach den Sperren neu lesen.
            $stmt = $pdo->prepare('SELECT offen_einzeln FROM v_entnahme WHERE entnahme_id = ?');
            $stmt->execute([$eid]);
            $offenJetzt = (int) $stmt->fetchColumn();

            $menge   = (int) $daten['menge'];
            $einheit = empty($art['ve_inhalt']) ? 'einzeln' : $daten['einheit'];
            $stueck  = $einheit === 'gebinde' ? $menge * (int) $art['ve_inhalt'] : $menge;

            if ($offenJetzt < 1) {
                throw new RuntimeException('Zu dieser Entnahme ist bereits alles zurückgebucht.');
            }
            if ($stueck > $offenJetzt) {
                throw new RuntimeException(
                    'Offen sind nur noch ' . einzeln_text($art, $offenJetzt)
                    . ', zurückgebucht werden sollen ' . einheit_text($art, $einheit, $menge) . '.'
                );
            }

            [$neueMenge, $neueLose] = bestand_rechnen($art, $menge, $einheit, true);
            $pdo->prepare('UPDATE artikel SET menge = ?, lose = ? WHERE artikel_id = ?')
                ->execute([$neueMenge, $neueLose, (int) $e['artikel_id']]);

            chargen_mitbuchen($pdo, $art, (int) $e['artikel_id'], $stueck, true,
                              $e['mhd'] ?? mhd_aus_eingabe($daten['mhd']));

            $pdo->prepare(
                'INSERT INTO rueckgabe (entnahme_id, menge, einheit, datum, notiz, erfasst_von)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $eid,
                $menge,
                $einheit,
                $daten['datum'],
                $daten['notiz'] !== '' ? $daten['notiz'] : null,
                (int) benutzer()['benutzer_id'],
            ]);

            $pdo->commit();

            $rest = $offenJetzt - $stueck;
            $text = einheit_text($art, $einheit, $menge) . ' ' . $art['artikelname'] . ' zurückgebucht.';
            if ($rest === 0) {
                $text .= ' Damit ist alles zurück.';
            } elseif ($e['grund'] === GRUND_LEIHE) {
                $text .= ' Noch unterwegs: ' . einzeln_text($art, $rest) . '.';
            } else {
                $text .= ' Als verbraucht gelten: ' . einzeln_text($art, $rest) . '.';
            }
            flash($text);
            redirect('entnahmen.php');

        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            $fehler[] = $ex->getMessage();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            error_log('Rückgabe buchen: ' . $ex->getMessage());
            $fehler[] = 'Die Rückgabe konnte nicht gebucht werden.';
        }
        // Nach einem Fehler den aktuellen Stand zeigen.
        $e     = entnahme_laden($pdo, $eid);
        $offen = (int) $e['offen_einzeln'];
    }
}

/* ===================================================================
   Anzeige
   =================================================================== */
$stmt = $pdo->prepare(
    'SELECT r.*, b.anzeigename AS gebucht_von
     FROM rueckgabe r
     LEFT JOIN benutzer b ON b.benutzer_id = r.erfasst_von
     WHERE r.entnahme_id = ?
     ORDER BY r.datum, r.rueckgabe_id'
);
$stmt->execute([$eid]);
$bisher = $stmt->fetchAll();

$leihe = $e['grund'] === GRUND_LEIHE;

$titel = 'Rückgabe buchen';
$aktiv = 'entnahmen';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Rückgabe buchen</h1>
  <span class="anzahl"><?= h(beleg_nr($eid)) ?></span>
  <span class="rechts">
    <a class="btn" href="schein.php?id=<?= $eid ?>">Schein</a>
    <a class="btn" href="entnahmen.php">Protokoll</a>
  </span>
</div>

<?php if ($fehler): ?>
  <ul class="fehlerliste">
    <?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<div class="karte">
  <div>
    <span class="invnr"><?= h($e['inventar_nr']) ?></span>
    <strong style="margin-left:8px"><?= h($e['artikelname']) ?></strong>
  </div>
  <div style="margin-top:6px">
    <span class="grund grund--<?= h($e['grund']) ?>"><?= h(grund_label($e['grund'])) ?></span>
    am <?= h(datum_de($e['entnommen_am'])) ?>
    <?php if ($e['person']): ?> · <?= h($e['person']) ?><?php endif; ?>
    <?php if ($e['bezug']): ?> · <?= h($e['bezug']) ?><?php endif; ?>
  </div>
  <dl class="schein-daten" style="margin-top:12px">
    <div><dt>Ausgegeben</dt><dd><?= h(einheit_text($e, (string) $e['einheit'], (int) $e['menge'])) ?></dd></div>
    <div><dt>Schon zurück</dt><dd><?= (int) $e['zurueck_einzeln'] > 0 ? h(einzeln_text($e, (int) $e['zurueck_einzeln'])) : '—' ?></dd></div>
    <div><dt><?= $leihe ? 'Noch unterwegs' : 'Verbraucht, sofern nichts mehr kommt' ?></dt>
         <dd><?= $offen > 0 ? h(einzeln_text($e, $offen)) : '—' ?></dd></div>
  </dl>
</div>

<?php if ($offen < 1): ?>
  <p class="leer">Zu dieser Entnahme ist alles zurückgebucht<?= $e['letzte_rueckgabe'] ? ', zuletzt am ' . h(datum_de($e['letzte_rueckgabe'])) : '' ?>.</p>
<?php else: ?>

<form class="form" method="post" action="rueckgabe.php">
  <?= csrf_field() ?>
  <input type="hidden" name="entnahme_id" value="<?= $eid ?>">

  <div class="paar">
    <div class="zeile">
      <label for="menge">Menge zurück</label>
      <input type="number" id="menge" name="menge" min="1" step="1" required autofocus
             value="<?= h($daten['menge']) ?>">
    </div>
    <?php if ($ve > 0): ?>
      <div class="zeile">
        <label for="einheit">Einheit</label>
        <select id="einheit" name="einheit">
          <option value="gebinde" <?= $daten['einheit'] === 'gebinde' ? 'selected' : '' ?>>
            <?= h(mehrzahl($e['gebinde_einheit'] ?: 'Gebinde')) ?>
          </option>
          <option value="einzeln" <?= $daten['einheit'] === 'einzeln' ? 'selected' : '' ?>>
            <?= h($e['ve_einheit'] ?: 'Einzelstücke') ?>
          </option>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="einheit" value="einzeln">
    <?php endif; ?>
    <div class="zeile">
      <label for="datum">Datum</label>
      <input type="date" id="datum" name="datum" required
             min="<?= h($e['entnommen_am']) ?>" value="<?= h($daten['datum']) ?>">
    </div>
    <?php if ($mhdNoetig): ?>
      <div class="zeile">
        <label for="mhd">MHD der Ware</label>
        <input type="date" id="mhd" name="mhd" required value="<?= h($daten['mhd']) ?>">
      </div>
    <?php endif; ?>
  </div>
  <?php if ((int) $e['mit_chargen'] === 1 && $mhdBekannt): ?>
    <p class="hinweis">Geht zurück in die Charge mit MHD <?= h(datum_de($e['mhd'])) ?>.</p>
  <?php endif; ?>

  <div class="zeile">
    <label for="notiz">Notiz</label>
    <input type="text" id="notiz" name="notiz" maxlength="255" value="<?= h($daten['notiz']) ?>"
           placeholder="z. B. Zustand, wer zurückgebracht hat">
  </div>

  <p class="hinweis">
    <?php if ($leihe): ?>
      Die Leihe bleibt offen, bis alles zurück ist. Kommt nur ein Teil, buche ihn jetzt
      und den Rest später.
    <?php else: ?>
      Buche zurück, was vom Einsatz unverbraucht wiederkommt – auch in mehreren Teilen.
      Was nicht zurückkommt, gilt als verbraucht.
    <?php endif; ?>
  </p>

  <div class="knoepfe">
    <button class="btn btn--primaer" type="submit">Rückgabe buchen</button>
    <a class="btn" href="entnahmen.php">Abbrechen</a>
  </div>
</form>

<?php endif; ?>

<?php if ($bisher): ?>
  <h2 class="abschnitt">Bisherige Rückgaben</h2>
  <table class="tabelle">
    <thead>
      <tr><th>Datum</th><th style="text-align:right">Menge</th><th>Notiz</th><th>Gebucht von</th></tr>
    </thead>
    <tbody>
    <?php foreach ($bisher as $r): ?>
      <tr>
        <td data-label="Datum"><span class="faellig-datum"><?= h(datum_de($r['datum'])) ?></span></td>
        <td class="zahl" data-label="Menge">
          <span class="menge-plus">+<?= h(einheit_text($e, (string) $r['einheit'], (int) $r['menge'])) ?></span>
        </td>
        <td data-label="Notiz"><?= h($r['notiz'] ?? '') ?></td>
        <td data-label="Gebucht von"><?= h($r['gebucht_von'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
