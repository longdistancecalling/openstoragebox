<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

// Rückgaben bucht rueckgabe.php – auch in Teilen und auch nach einem Einsatz.

/* ===================================================================
   Filter
   =================================================================== */
$grund = (string) ($_GET['grund'] ?? '');
if ($grund !== '' && !isset(ENTNAHME_GRUENDE[$grund]) && !isset(ZUGANG_GRUENDE[$grund])) {
    $grund = '';
}

// Richtung: leer = beides, sonst zugang oder abgang.
$richtung = (string) ($_GET['richtung'] ?? '');
if (!in_array($richtung, ['zugang', 'abgang'], true)) {
    $richtung = '';
}
$von = (string) ($_GET['von'] ?? '');
$bis = (string) ($_GET['bis'] ?? '');
$kat = (string) ($_GET['kat'] ?? '');

$bedingungen = [];
$werte       = [];

if ($grund !== '') {
    $bedingungen[] = 'e.grund = :grund';
    $werte[':grund'] = $grund;
}
if ($richtung !== '') {
    $bedingungen[] = 'e.richtung = :richtung';
    $werte[':richtung'] = $richtung;
}
if ($kat === 'ohne') {
    // Eigener Zweig: kategorie_id IS NULL lässt sich nicht mit = abfragen.
    $bedingungen[] = 'a.kategorie_id IS NULL';
} elseif ($kat !== '' && ctype_digit($kat)) {
    $bedingungen[] = 'a.kategorie_id = :kat';
    $werte[':kat'] = (int) $kat;
}
if (DateTimeImmutable::createFromFormat('Y-m-d', $von)) {
    $bedingungen[] = 'e.datum >= :von';
    $werte[':von'] = $von;
}
if (DateTimeImmutable::createFromFormat('Y-m-d', $bis)) {
    $bedingungen[] = 'e.datum <= :bis';
    $werte[':bis'] = $bis;
}
$where = $bedingungen ? 'WHERE ' . implode(' AND ', $bedingungen) : '';

// Bei Abgängen kommt der Rückgabestand aus v_entnahme dazu; bei Zugängen
// bleiben die Spalten leer.
$sql = "SELECT e.*, a.artikelname, a.inventar_nr, a.ean,
               a.ve_inhalt, a.ve_einheit, a.gebinde_einheit,
               a.kategorie_id, k.bezeichnung AS kategorie,
               b.anzeigename AS gebucht_von,
               ve.zurueck_einzeln, ve.offen_einzeln, ve.letzte_rueckgabe
        FROM v_bewegung e
        JOIN artikel a ON a.artikel_id = e.artikel_id
        LEFT JOIN kategorie k ON k.kategorie_id = a.kategorie_id
        LEFT JOIN benutzer b ON b.benutzer_id = e.erfasst_von
        LEFT JOIN v_entnahme ve ON ve.entnahme_id = e.entnahme_id AND e.richtung = 'abgang'
        $where
        ORDER BY e.datum DESC, e.erfasst_am DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$zeilen = $stmt->fetchAll();

$kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();

// Summe der gefilterten Menge – bei einer Kategorieauswahl die eigentlich
// interessante Zahl: wie viel wurde davon insgesamt entnommen.
$summeZu = 0; $summeAb = 0;
foreach ($zeilen as $z) {
    if ($z['richtung'] === 'zugang') { $summeZu += (int) $z['menge']; }
    else                             { $summeAb += (int) $z['menge']; }
}

// Offene Leihen immer separat, unabhängig vom Filter – das ist die
// Frage, die im Lager am häufigsten gestellt wird.
$offen = $pdo->query(
    "SELECT e.*, a.artikelname, a.inventar_nr, a.ve_inhalt, a.ve_einheit, a.gebinde_einheit,
            DATEDIFF(CURDATE(), e.entnommen_am) AS tage_weg
       FROM v_entnahme e
       JOIN artikel a ON a.artikel_id = e.artikel_id
      WHERE e.grund = '" . GRUND_LEIHE . "' AND e.offen_einzeln > 0
      ORDER BY e.entnommen_am"
)->fetchAll();

$titel = 'Bewegungen';
$aktiv = 'entnahmen';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Bewegungen</h1>
  <span class="anzahl">
    <?= count($zeilen) ?> Buchungen<?= $summeZu ? ' · +' . $summeZu : '' ?><?= $summeAb ? ' · −' . $summeAb : '' ?> Stück<?= $offen ? ' · ' . count($offen) . ' Leihen offen' : '' ?>
  </span>
  <span class="rechts">
    <?php if ($offen): ?><a class="btn" href="schein.php?offen=1">Leihscheine drucken</a><?php endif; ?>
    <a class="btn" href="schein.php?blanko=entnahme">Blanko-Scheine</a>
    <?php if (darf('bearbeiter')): ?>
      <a class="btn" href="zugang.php">Zugang buchen</a>
      <a class="btn btn--primaer" href="entnahme.php">Entnahme buchen</a>
    <?php endif; ?>
  </span>
</div>

<?php if ($offen): ?>
  <h2 class="abschnitt">Offene Leihen</h2>
  <table class="tabelle">
    <thead>
      <tr>
        <th>Seit</th><th>Artikel</th><th>Bei wem</th>
        <th style="text-align:right">Menge</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($offen as $o):
        $lang = (int) $o['tage_weg'] > 60;
    ?>
      <tr<?= $lang ? ' class="stufe-faellig"' : '' ?>>
        <td data-label="Seit">
          <span class="faellig">
            <span class="faellig-datum"><?= h(datum_de($o['entnommen_am'])) ?></span>
            <span class="faellig-typ"><?= (int) $o['tage_weg'] ?> Tage unterwegs</span>
          </span>
        </td>
        <td data-label="Artikel">
          <span class="invnr"><?= h($o['inventar_nr']) ?></span>
          <span class="name" style="margin-left:6px"><?= h($o['artikelname']) ?></span>
          <?php if ($o['bezug']): ?><span class="notiz"><?= h($o['bezug']) ?></span><?php endif; ?>
        </td>
        <td data-label="Bei wem"><?= h($o['person'] ?? '—') ?></td>
        <td class="zahl" data-label="Menge">
          <?= h(einzeln_text($o, (int) $o['offen_einzeln'])) ?>
          <?php if ((int) $o['zurueck_einzeln'] > 0): ?>
            <span class="notiz">von <?= h(einheit_text($o, (string) $o['einheit'], (int) $o['menge'])) ?></span>
          <?php endif; ?>
        </td>
        <td class="aktion" data-label="">
          <a class="btn btn--klein" href="schein.php?id=<?= (int) $o['entnahme_id'] ?>">Schein</a>
          <?php if (darf('bearbeiter')): ?>
            <a class="btn btn--klein btn--primaer" href="rueckgabe.php?id=<?= (int) $o['entnahme_id'] ?>">Rückgabe</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2 class="abschnitt">Protokoll aller Bewegungen</h2>

<form class="filter" method="get" action="entnahmen.php">
  <div class="feld">
    <label for="richtung">Richtung</label>
    <select id="richtung" name="richtung">
      <option value="">Zugang und Abgang</option>
      <option value="zugang" <?= $richtung === 'zugang' ? 'selected' : '' ?>>nur Zugänge</option>
      <option value="abgang" <?= $richtung === 'abgang' ? 'selected' : '' ?>>nur Abgänge</option>
    </select>
  </div>
  <div class="feld">
    <label for="grund">Grund</label>
    <select id="grund" name="grund">
      <option value="">alle</option>
      <optgroup label="Abgang">
        <?php foreach (ENTNAHME_GRUENDE as $wert => $label): ?>
          <option value="<?= h($wert) ?>" <?= $grund === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <optgroup label="Zugang">
        <?php foreach (ZUGANG_GRUENDE as $wert => $label): ?>
          <option value="<?= h($wert) ?>" <?= $grund === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </optgroup>
    </select>
  </div>
  <div class="feld">
    <label for="kat">Kategorie</label>
    <select id="kat" name="kat">
      <option value="">alle</option>
      <?php foreach ($kategorien as $k): ?>
        <option value="<?= (int) $k['kategorie_id'] ?>"
          <?= $kat === (string) $k['kategorie_id'] ? ' selected' : '' ?>>
          <?= h($k['bezeichnung']) ?>
        </option>
      <?php endforeach; ?>
      <option value="ohne" <?= $kat === 'ohne' ? 'selected' : '' ?>>ohne Kategorie</option>
    </select>
  </div>
  <div class="feld">
    <label for="von">Von</label>
    <input type="date" id="von" name="von" value="<?= h($von) ?>">
  </div>
  <div class="feld">
    <label for="bis">Bis</label>
    <input type="date" id="bis" name="bis" value="<?= h($bis) ?>">
  </div>
  <button class="btn" type="submit">Anzeigen</button>
  <?php if ($grund !== '' || $von !== '' || $bis !== '' || $kat !== '' || $richtung !== ''): ?>
    <a class="btn" href="entnahmen.php">Zurücksetzen</a>
  <?php endif; ?>
  <a class="btn" href="export.php?<?= h(http_build_query(array_filter([
        'was' => 'entnahmen', 'format' => 'csv',
        'grund' => $grund, 'kat' => $kat, 'von' => $von, 'bis' => $bis, 'richtung' => $richtung,
     ]))) ?>">CSV dieser Auswahl</a>
</form>

<?php if (!$zeilen): ?>
  <p class="leer">Für diese Auswahl sind keine Buchungen erfasst.</p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th>Datum</th><th>Artikel</th><th>Kategorie</th><th>Grund</th>
      <th style="text-align:right">Menge</th><th>Person / Bezug</th><th>Status</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($zeilen as $z): ?>
    <tr>
      <td data-label="Datum"><span class="faellig-datum"><?= h(datum_de($z['datum'])) ?></span></td>
      <td data-label="Artikel">
        <span class="invnr"><?= h($z['inventar_nr']) ?></span>
        <span class="name" style="margin-left:6px"><?= h($z['artikelname']) ?></span>
        <?php if ($z['notiz']): ?><span class="notiz"><?= h($z['notiz']) ?></span><?php endif; ?>
      </td>
      <td data-label="Kategorie">
        <?php if ($z['kategorie']): ?>
          <a href="<?= h(url_mit(['kat' => (string) $z['kategorie_id']])) ?>"
             style="text-decoration:none" title="nach dieser Kategorie filtern">
            <span class="kat"><?= h($z['kategorie']) ?></span></a>
        <?php else: ?>
          <span class="kat kat--leer">ohne</span>
        <?php endif; ?>
      </td>
      <td data-label="Grund">
        <span class="grund grund--<?= h($z['grund']) ?>"><?= h($z['richtung'] === 'zugang'
              ? zugang_label($z['grund']) : grund_label($z['grund'])) ?></span>
      </td>
      <td class="zahl" data-label="Menge">
        <span class="<?= $z['richtung'] === 'zugang' ? 'menge-plus' : 'menge-minus' ?>">
          <?= $z['richtung'] === 'zugang' ? '+' : '−' ?><?= (int) $z['menge'] ?>
        </span>
        <?php if ($z['ve_inhalt'] && $z['einheit'] === 'gebinde'): ?>
          <span class="notiz"><?= h(mehrzahl($z['gebinde_einheit'] ?: 'Gebinde')) ?>
            = <?= (int) $z['menge'] * (int) $z['ve_inhalt'] ?> <?= h($z['ve_einheit'] ?: 'Stück') ?></span>
        <?php elseif ($z['ve_inhalt']): ?>
          <span class="notiz"><?= h($z['ve_einheit'] ?: 'Stück') ?></span>
        <?php endif; ?>
      </td>
      <td data-label="Person / Bezug">
        <?= h($z['person'] ?? '') ?>
        <?php if ($z['bezug']): ?><span class="notiz"><?= h($z['bezug']) ?></span><?php endif; ?>
        <?php if ($z['gebucht_von']): ?><span class="notiz">gebucht von <?= h($z['gebucht_von']) ?></span><?php endif; ?>
      </td>
      <td data-label="Status">
        <?php
          $zurueck = (int) ($z['zurueck_einzeln'] ?? 0);
          $rest    = (int) ($z['offen_einzeln'] ?? 0);
        ?>
        <?php if ($z['richtung'] === 'zugang' && $z['entnahme_id']): ?>
          <span class="notiz">Rückgabe zu
            <a href="rueckgabe.php?id=<?= (int) $z['entnahme_id'] ?>"><?= h(beleg_nr((int) $z['entnahme_id'])) ?></a></span>
        <?php elseif ($z['richtung'] === 'zugang'): ?>
          <span class="notiz">Zugang</span>
        <?php elseif ($z['grund'] === GRUND_LEIHE): ?>
          <?php if ($rest < 1): ?>
            <span class="marke marke--gruen">zurück <?= h(datum_de($z['letzte_rueckgabe'])) ?></span>
          <?php elseif ($zurueck > 0): ?>
            <span class="marke marke--amber">teilweise zurück</span>
            <span class="notiz">offen: <?= h(einzeln_text($z, $rest)) ?></span>
          <?php else: ?>
            <span class="marke marke--amber">offen</span>
          <?php endif; ?>
        <?php elseif (rueckgabe_moeglich($z['grund'])): ?>
          <?php if ($zurueck > 0): ?>
            <span class="marke marke--gruen"><?= h(einzeln_text($z, $zurueck)) ?> zurück</span>
            <span class="notiz"><?= $rest > 0 ? h(einzeln_text($z, $rest)) . ' verbraucht' : 'nichts verbraucht' ?></span>
          <?php else: ?>
            <span class="notiz">verbraucht</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="notiz">endgültig</span>
        <?php endif; ?>
      </td>
      <td class="aktion" data-label="">
        <?php if ($z['richtung'] === 'abgang'): ?>
          <a class="btn btn--klein" href="schein.php?id=<?= (int) $z['bewegung_id'] ?>">Schein</a>
          <?php if (rueckgabe_moeglich($z['grund']) && $rest > 0 && darf('bearbeiter')): ?>
            <a class="btn btn--klein" href="rueckgabe.php?id=<?= (int) $z['bewegung_id'] ?>">Rückgabe</a>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
