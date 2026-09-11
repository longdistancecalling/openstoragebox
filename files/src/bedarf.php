<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Handlungsbedarf.
 *
 * Fasst die drei Fragen zusammen, die jemand vor einer Beschaffung
 * stellt: Was fehlt, was ist abgelaufen, was läuft demnächst ab.
 * Einzeln verteilt müsste man drei Seiten abklappern und die Antworten
 * selbst zusammensetzen.
 *
 * Die Reihenfolge ist bewusst: Überfälliges zuerst, weil es schon
 * passiert ist; dann Unterdeckung, weil sie den Einsatz gefährdet; dann
 * das, was bald ansteht.
 */

// Überfällige Prüfungen und abgelaufene Artikel
$ueberfaellig = $pdo->query(
    'SELECT * FROM v_artikel
      WHERE faellig_am IS NOT NULL AND tage_bis_faellig < 0
      ORDER BY faellig_am'
)->fetchAll();

// Unterdeckung
$unterdeckung = $pdo->query(
    'SELECT * FROM v_artikel
      WHERE unter_soll = 1
      ORDER BY (fehlmenge / NULLIF(soll_bestand,0)) DESC, artikelname'
)->fetchAll();

// Bald fällig – Zeitraum aus den Einstellungen
$stmt = $pdo->prepare(
    'SELECT * FROM v_artikel
      WHERE faellig_am IS NOT NULL AND tage_bis_faellig >= 0 AND tage_bis_faellig <= ?
      ORDER BY faellig_am'
);
$stmt->execute([WARNUNG_TAGE]);
$bald = $stmt->fetchAll();

$gesamt = count($ueberfaellig) + count($unterdeckung) + count($bald);

/** Eine Tabelle mit Artikeln, je nach Abschnitt unterschiedlich beschriftet. */
function bedarfstabelle(array $zeilen, string $art): void
{
    ?>
    <table class="tabelle">
      <thead>
        <tr>
          <th>Nr.</th><th>Artikel</th><th>Lagerort</th>
          <th style="text-align:right">Bestand</th>
          <th><?= $art === 'soll' ? 'Fehlt' : 'Termin' ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($zeilen as $a):
          $tage = $a['tage_bis_faellig'] === null ? null : (int) $a['tage_bis_faellig'];
      ?>
        <tr class="<?= $art === 'bald' ? 'stufe-bald' : 'stufe-faellig' ?>">
          <td data-label="Nr."><span class="invnr"><?= h($a['inventar_nr']) ?></span></td>
          <td data-label="Artikel">
            <span class="name"><?= h($a['artikelname']) ?></span>
            <?php if ($a['notiz']): ?><span class="notiz"><?= h($a['notiz']) ?></span><?php endif; ?>
          </td>
          <td data-label="Lagerort"><?= lagerort($a) ?></td>
          <td class="zahl" data-label="Bestand">
            <?= (int) $a['gesamt_einzeln'] ?>
            <?php if ($a['hat_gebinde']): ?>
              <span class="notiz"><?= h($a['ve_einheit'] ?: 'Stück') ?></span>
            <?php endif; ?>
          </td>
          <td data-label="<?= $art === 'soll' ? 'Fehlt' : 'Termin' ?>">
            <?php if ($art === 'soll'): ?>
              <span class="marke marke--rot"><?= (int) $a['fehlmenge'] ?> <?= h($a['ve_einheit'] ?: 'Stück') ?></span>
              <span class="notiz">Soll <?= (int) $a['soll_bestand'] ?><?php
                if ($a['hat_gebinde']) {
                    $g = (int) ceil((int) $a['fehlmenge'] / max(1, (int) $a['ve_inhalt']));
                    echo ' · ' . $g . ' ' . h($g === 1 ? ($a['gebinde_einheit'] ?: 'Gebinde')
                                                       : mehrzahl($a['gebinde_einheit'] ?: 'Gebinde'))
                       . ' nachbestellen';
                } ?></span>
            <?php else: ?>
              <span class="faellig">
                <span class="faellig-datum"><?= h(datum_de($a['faellig_am'])) ?></span>
                <span class="faellig-typ">
                  <?= h(faellig_label($a['faellig_typ'])) ?>
                  <?= $tage < 0 ? '· seit ' . abs($tage) . ' Tagen' : '· in ' . $tage . ' Tagen' ?>
                </span>
              </span>
            <?php endif; ?>
          </td>
          <td class="aktion" data-label="">
            <?php if (darf('bearbeiter')): ?>
              <a class="btn btn--klein" href="zugang.php?artikel=<?= (int) $a['artikel_id'] ?>">Einbuchen</a>
            <?php endif; ?>
            <a class="btn btn--klein" href="artikel.php?id=<?= (int) $a['artikel_id'] ?>">Bearbeiten</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

$titel = 'Handlungsbedarf';
$aktiv = 'bedarf';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Handlungsbedarf</h1>
  <span class="anzahl"><?= $gesamt ?> Positionen</span>
  <span class="rechts">
    <a class="btn" href="faellig.php">Alle Fälligkeiten</a>
    <a class="btn" href="index.php?ort=&amp;soll=unter">Unterdeckung in der Liste</a>
  </span>
</div>

<div class="kennzahlen">
  <div class="kennzahl<?= $ueberfaellig ? ' kennzahl--rot' : '' ?>">
    <span class="kz-wert"><?= count($ueberfaellig) ?></span>
    <span class="kz-label">überfällig</span>
  </div>
  <div class="kennzahl<?= $unterdeckung ? ' kennzahl--rot' : '' ?>">
    <span class="kz-wert"><?= count($unterdeckung) ?></span>
    <span class="kz-label">unter Sollbestand</span>
  </div>
  <div class="kennzahl<?= $bald ? ' kennzahl--amber' : '' ?>">
    <span class="kz-wert"><?= count($bald) ?></span>
    <span class="kz-label">in <?= WARNUNG_TAGE ?> Tagen fällig</span>
  </div>
</div>

<?php if ($gesamt === 0): ?>
  <p class="leer">
    Nichts zu tun: kein Termin überschritten, kein Bestand unter Soll,
    in den nächsten <?= WARNUNG_TAGE ?> Tagen steht nichts an.
  </p>
<?php endif; ?>

<?php if ($ueberfaellig): ?>
  <h2 class="abschnitt">Überfällig – <?= count($ueberfaellig) ?> Positionen</h2>
  <?php bedarfstabelle($ueberfaellig, 'ueberfaellig'); ?>
<?php endif; ?>

<?php if ($unterdeckung): ?>
  <h2 class="abschnitt">Unter Sollbestand – <?= count($unterdeckung) ?> Positionen</h2>
  <?php bedarfstabelle($unterdeckung, 'soll'); ?>
  <p class="hinweis">
    Sortiert nach dem Anteil, der fehlt – nicht nach der absoluten Menge.
    Ein Artikel, von dem nur ein Fünftel da ist, drängt mehr als einer, bei
    dem zehn von tausend Stück fehlen.
  </p>
<?php endif; ?>

<?php if ($bald): ?>
  <h2 class="abschnitt">In den nächsten <?= WARNUNG_TAGE ?> Tagen fällig – <?= count($bald) ?> Positionen</h2>
  <?php bedarfstabelle($bald, 'bald'); ?>
  <p class="hinweis">
    Die Vorwarnzeit lässt sich unter
    <?= darf('admin') ? '<a href="einstellungen.php">Einstellungen</a>' : 'Einstellungen' ?>
    ändern – bei Prüfterminen, für die ein Fachbetrieb bestellt werden muss,
    sind 60 bis 90 Tage oft realistischer als <?= WARNUNG_TAGE ?>.
  </p>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
