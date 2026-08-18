<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';

$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;

/* ===================================================================
   POST
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');

    /* ---- Neue Inventur starten ------------------------------------ */
    if ($aktion === 'starten') {
        $bez = trim((string) ($_POST['bezeichnung'] ?? ''));
        if ($bez === '') {
            $bez = 'Inventur ' . date('Y');
        }

        try {
            /*
             * Der Start friert den aktuellen Bestand als Soll ein und
             * kopiert Name und Lagerort in die Positionen. Eine
             * abgeschlossene Inventur ist ein Beleg – sie muss auch dann
             * noch lesbar sein, wenn Artikel später umbenannt oder
             * umgelagert werden.
             */
            $pdo->beginTransaction();

            $pdo->prepare('INSERT INTO inventur (bezeichnung, begonnen_am) VALUES (?, CURDATE())')
                ->execute([$bez]);
            $neu = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO inventur_position
                    (inventur_id, artikel_id, inventar_nr, artikelname, lagerort, soll_menge)
                 SELECT ?, artikel_id, inventar_nr, artikelname,
                        IFNULL(lagerort_text, "ohne Lagerort"), menge
                   FROM v_artikel'
            )->execute([$neu]);

            $anzahl = (int) $pdo->query('SELECT COUNT(*) FROM inventur_position WHERE inventur_id = ' . $neu)
                                ->fetchColumn();
            $pdo->commit();

            flash("Inventur angelegt mit $anzahl Positionen.");
            redirect('inventur_zaehlen.php?id=' . $neu);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Inventur starten: ' . $e->getMessage());
            flash('Die Inventur konnte nicht angelegt werden.', 'fehler');
            redirect('inventur.php');
        }
    }

    /* ---- Abschließen ---------------------------------------------- */
    if ($aktion === 'abschliessen' && $id !== null) {
        $uebernehmen = isset($_POST['bestand_uebernehmen']);

        try {
            $pdo->beginTransaction();

            if ($uebernehmen) {
                // Nur gezählte Positionen mit Abweichung anfassen.
                $pdo->prepare(
                    'UPDATE artikel a
                       JOIN inventur_position p ON p.artikel_id = a.artikel_id
                        SET a.menge = p.ist_menge
                      WHERE p.inventur_id = ?
                        AND p.ist_menge IS NOT NULL
                        AND p.ist_menge <> p.soll_menge'
                )->execute([$id]);
            }

            $pdo->prepare('UPDATE inventur SET abgeschlossen_am = CURDATE() WHERE inventur_id = ?')
                ->execute([$id]);

            $pdo->commit();
            flash($uebernehmen
                ? 'Inventur abgeschlossen, die Bestände wurden auf die Zählwerte gesetzt.'
                : 'Inventur abgeschlossen. Die Bestände blieben unverändert.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Inventur abschließen: ' . $e->getMessage());
            flash('Die Inventur konnte nicht abgeschlossen werden.', 'fehler');
        }
        redirect('inventur.php?id=' . $id);
    }

    /* ---- Löschen --------------------------------------------------- */
    if ($aktion === 'loeschen' && $id !== null) {
        $pdo->prepare('DELETE FROM inventur WHERE inventur_id = ?')->execute([$id]);
        flash('Inventur gelöscht.');
        redirect('inventur.php');
    }
}

/* ===================================================================
   Detailansicht einer Inventur
   =================================================================== */
if ($id !== null) {
    $stmt = $pdo->prepare('SELECT * FROM inventur WHERE inventur_id = ?');
    $stmt->execute([$id]);
    $inv = $stmt->fetch();

    if (!$inv) {
        flash('Diese Inventur existiert nicht.', 'fehler');
        redirect('inventur.php');
    }

    $stmt = $pdo->prepare(
        'SELECT *, (ist_menge - soll_menge) AS differenz
         FROM inventur_position WHERE inventur_id = ?
         ORDER BY (ist_menge IS NULL), ABS(IFNULL(ist_menge,0) - soll_menge) DESC, artikelname'
    );
    $stmt->execute([$id]);
    $positionen = $stmt->fetchAll();

    $gezaehlt = 0; $abweichend = 0; $plus = 0; $minus = 0;
    foreach ($positionen as $p) {
        if ($p['ist_menge'] === null) {
            continue;
        }
        $gezaehlt++;
        $d = (int) $p['differenz'];
        if ($d !== 0) {
            $abweichend++;
            if ($d > 0) { $plus += $d; } else { $minus += abs($d); }
        }
    }
    $offen = count($positionen) - $gezaehlt;

    $titel = $inv['bezeichnung'];
    $aktiv = 'inventur';
    require __DIR__ . '/inc/header.php';
    ?>

    <div class="kopf">
      <h1><?= h($inv['bezeichnung']) ?></h1>
      <span class="anzahl">
        begonnen <?= h(datum_de($inv['begonnen_am'])) ?><?php
        if ($inv['abgeschlossen_am']) { echo ' · abgeschlossen ' . h(datum_de($inv['abgeschlossen_am'])); } ?>
      </span>
      <span class="rechts">
        <?php if (!$inv['abgeschlossen_am']): ?>
          <a class="btn btn--primaer" href="inventur_zaehlen.php?id=<?= $id ?>">Zählen</a>
        <?php endif; ?>
        <a class="btn" href="inventur_zaehlen.php?id=<?= $id ?>&amp;format=druck">Zählliste drucken</a>
      </span>
    </div>

    <div class="kennzahlen">
      <div class="kennzahl"><span class="kz-wert"><?= count($positionen) ?></span><span class="kz-label">Positionen</span></div>
      <div class="kennzahl"><span class="kz-wert"><?= $gezaehlt ?></span><span class="kz-label">gezählt</span></div>
      <div class="kennzahl<?= $offen > 0 ? ' kennzahl--amber' : '' ?>"><span class="kz-wert"><?= $offen ?></span><span class="kz-label">offen</span></div>
      <div class="kennzahl<?= $abweichend > 0 ? ' kennzahl--rot' : '' ?>"><span class="kz-wert"><?= $abweichend ?></span><span class="kz-label">Abweichungen</span></div>
      <div class="kennzahl"><span class="kz-wert">−<?= $minus ?> / +<?= $plus ?></span><span class="kz-label">Stück Differenz</span></div>
    </div>

    <?php if (!$inv['abgeschlossen_am']): ?>
      <form class="form" method="post" action="inventur.php?id=<?= $id ?>" style="max-width:none;margin-bottom:18px"
            onsubmit="return confirm('Inventur abschließen? Danach ist keine Zählung mehr möglich.');">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="abschliessen">
        <label class="radio" style="margin-bottom:10px">
          <input type="checkbox" name="bestand_uebernehmen" value="1" checked>
          <span>Bestände auf die gezählten Werte setzen (<?= $abweichend ?> Artikel betroffen)</span>
        </label>
        <div class="knoepfe">
          <button class="btn btn--primaer" type="submit">Inventur abschließen</button>
          <?php if ($offen > 0): ?>
            <span class="hinweis" style="align-self:center"><?= $offen ?> Positionen sind noch nicht gezählt und bleiben unverändert.</span>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>

    <h2 class="abschnitt">Positionen</h2>
    <table class="tabelle">
      <thead>
        <tr>
          <th>Nr.</th><th>Artikel</th><th>Lagerort</th>
          <th style="text-align:right">Soll</th><th style="text-align:right">Ist</th>
          <th style="text-align:right">Differenz</th><th>Bemerkung</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($positionen as $p):
          $d = $p['ist_menge'] === null ? null : (int) $p['differenz'];
      ?>
        <tr<?= $d !== null && $d !== 0 ? ' class="stufe-faellig"' : '' ?>>
          <td data-label="Nr."><span class="invnr"><?= h($p['inventar_nr'] ?? '') ?></span></td>
          <td data-label="Artikel"><span class="name"><?= h($p['artikelname']) ?></span></td>
          <td data-label="Lagerort"><span class="platz-pfad"><?= h($p['lagerort'] ?? '') ?></span></td>
          <td class="zahl" data-label="Soll"><?= (int) $p['soll_menge'] ?></td>
          <td class="zahl" data-label="Ist">
            <?= $p['ist_menge'] === null ? '<span class="notiz">offen</span>' : (int) $p['ist_menge'] ?>
          </td>
          <td class="zahl" data-label="Differenz">
            <?php if ($d === null): ?>
              —
            <?php elseif ($d === 0): ?>
              <span class="marke marke--gruen">ok</span>
            <?php else: ?>
              <span class="marke marke--rot"><?= $d > 0 ? '+' : '' ?><?= $d ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Bemerkung"><span class="notiz"><?= h($p['bemerkung'] ?? '') ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <form class="form" method="post" action="inventur.php?id=<?= $id ?>" style="margin-top:16px"
          onsubmit="return confirm('Inventur mitsamt allen Zählwerten löschen?');">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="loeschen">
      <button class="btn btn--gefahr" type="submit">Inventur löschen</button>
    </form>

    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

/* ===================================================================
   Liste aller Inventuren
   =================================================================== */
$inventuren = $pdo->query(
    'SELECT i.*,
            COUNT(p.artikel_id) AS positionen,
            SUM(p.ist_menge IS NOT NULL) AS gezaehlt,
            SUM(p.ist_menge IS NOT NULL AND p.ist_menge <> p.soll_menge) AS abweichungen
     FROM inventur i
     LEFT JOIN inventur_position p ON p.inventur_id = i.inventur_id
     GROUP BY i.inventur_id
     ORDER BY i.begonnen_am DESC, i.inventur_id DESC'
)->fetchAll();

$laufend = null;
foreach ($inventuren as $i) {
    if (!$i['abgeschlossen_am']) { $laufend = $i; break; }
}

$titel = 'Inventur';
$aktiv = 'inventur';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Inventur</h1>
  <span class="anzahl"><?= count($inventuren) ?> erfasst</span>
</div>

<?php if ($laufend): ?>
  <p class="flash">
    Es läuft bereits eine Inventur: <strong><?= h($laufend['bezeichnung']) ?></strong> –
    <?= (int) $laufend['gezaehlt'] ?> von <?= (int) $laufend['positionen'] ?> Positionen gezählt.
    <a href="inventur_zaehlen.php?id=<?= (int) $laufend['inventur_id'] ?>">Weiterzählen</a>
  </p>
<?php else: ?>
  <form class="filter" method="post" action="inventur.php">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="starten">
    <div class="feld feld--breit">
      <label for="bezeichnung">Neue Inventur</label>
      <input type="text" id="bezeichnung" name="bezeichnung" maxlength="120"
             placeholder="Jahresinventur <?= date('Y') ?>">
    </div>
    <button class="btn btn--primaer" type="submit">Inventur starten</button>
  </form>
  <p class="hinweis" style="margin:-6px 0 18px">
    Der Start friert den aktuellen Bestand als Sollwert ein. Danach zählst du im Lager
    und trägst die Ist-Mengen ein.
  </p>
<?php endif; ?>

<?php if (!$inventuren): ?>
  <p class="leer">Noch keine Inventur durchgeführt.</p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th>Bezeichnung</th><th>Begonnen</th><th>Status</th>
      <th style="text-align:right">Fortschritt</th><th style="text-align:right">Abweichungen</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($inventuren as $i):
      $pos = (int) $i['positionen'];
      $gez = (int) $i['gezaehlt'];
      $abw = (int) $i['abweichungen'];
  ?>
    <tr>
      <td data-label="Bezeichnung"><span class="name"><?= h($i['bezeichnung']) ?></span></td>
      <td data-label="Begonnen"><span class="faellig-datum"><?= h(datum_de($i['begonnen_am'])) ?></span></td>
      <td data-label="Status">
        <?php if ($i['abgeschlossen_am']): ?>
          <span class="marke marke--gruen">abgeschlossen <?= h(datum_de($i['abgeschlossen_am'])) ?></span>
        <?php else: ?>
          <span class="marke marke--amber">läuft</span>
        <?php endif; ?>
      </td>
      <td class="zahl" data-label="Fortschritt"><?= $gez ?> / <?= $pos ?></td>
      <td class="zahl" data-label="Abweichungen">
        <?= $abw > 0 ? '<span class="marke marke--rot">' . $abw . '</span>' : $abw ?>
      </td>
      <td class="aktion" data-label="">
        <a class="btn btn--klein" href="inventur.php?id=<?= (int) $i['inventur_id'] ?>">Bericht</a>
        <?php if (!$i['abgeschlossen_am']): ?>
          <a class="btn btn--klein btn--primaer" href="inventur_zaehlen.php?id=<?= (int) $i['inventur_id'] ?>">Zählen</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
