<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Startseite ist das Terminal: wer die Anwendung ohne Seitenangabe
 * aufruft ("/"), landet dort. Ausdrücklich aufgerufen – "Artikel" in der
 * Seitenleiste, "Vollansicht" im Terminal – zeigt index.php weiter die
 * Artikelliste.
 */
if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === '/' && startseite() !== 'index.php') {
    redirect(startseite());
}

/* -------------------------------------------------------------------
   Sortierung
   Der Sortierparameter kommt aus der URL und darf niemals direkt in
   die Query. Deshalb eine Whitelist: nur bekannte Schlüssel werden
   auf ein festes ORDER BY abgebildet.
   ------------------------------------------------------------------- */
$ort_desc = 'ohne_kiste, lager DESC, LENGTH(regal) DESC, regal DESC, '
          . 'LENGTH(fach) DESC, fach DESC, LENGTH(kiste_nr) DESC, kiste_nr DESC, standort_frei DESC';

$sortierungen = [
    'nummer'         => 'LENGTH(inventar_nr), inventar_nr',
    'nummer_desc'    => 'LENGTH(inventar_nr) DESC, inventar_nr DESC',
    'name'           => 'artikelname ASC, ' . ORDER_LAGERORT,
    'name_desc'      => 'artikelname DESC, ' . ORDER_LAGERORT,
    'platz'          => ORDER_LAGERORT . ', artikelname ASC',
    'platz_desc'     => $ort_desc . ', artikelname ASC',
    'kategorie'      => 'kategorie IS NULL, kategorie ASC, artikelname ASC',
    'kategorie_desc' => 'kategorie IS NULL, kategorie DESC, artikelname ASC',
    'menge'          => 'menge ASC, artikelname ASC',
    'menge_desc'     => 'menge DESC, artikelname ASC',
    'faellig'        => 'faellig_am IS NULL, faellig_am ASC, artikelname ASC',
    'faellig_desc'   => 'faellig_am IS NULL, faellig_am DESC, artikelname ASC',
];

$sort = (string) ($_GET['sort'] ?? 'name');
if (!isset($sortierungen[$sort])) {
    $sort = 'name';
}

$suche = trim((string) ($_GET['q'] ?? ''));
$katId = (string) ($_GET['kat'] ?? '');
$ort   = (string) ($_GET['ort'] ?? '');   // '', 'kiste', 'fach', 'frei', 'ohne'
$soll  = (string) ($_GET['soll'] ?? '');  // '', 'unter', 'ueberwacht'

/* -------------------------------------------------------------------
   Abfrage zusammenbauen – Werte immer als Parameter, nie als String.
   ------------------------------------------------------------------- */
$bedingungen = [];
$werte       = [];

if ($suche !== '') {
    /*
     * Wichtig: mit PDO::ATTR_EMULATE_PREPARES => false bereitet MariaDB
     * das Statement selbst vor, und ein benannter Platzhalter darf dann
     * nur EINMAL vorkommen. Deshalb werden die Spalten zu einem
     * Suchtext zusammengefasst.
     */
    $bedingungen[] = "CONCAT_WS(' ', inventar_nr, IFNULL(ean,''), artikelname, IFNULL(kiste_nr,''), "
                   . "IFNULL(lager,''), IFNULL(regal,''), IFNULL(fach,''), IFNULL(standort_frei,''), "
                   . "IFNULL(notiz,''), IFNULL(zusatzfelder,'')) LIKE :q";
    $werte[':q']   = '%' . $suche . '%';
}

if ($katId !== '' && ctype_digit($katId)) {
    $bedingungen[] = 'kategorie_id = :kat';
    $werte[':kat'] = (int) $katId;
}

$bedingungen[] = match ($ort) {
    'kiste' => 'kiste_id IS NOT NULL',
    'fach'  => 'artikel_fach_id IS NOT NULL',
    'frei'  => 'standort_frei IS NOT NULL',
    'ohne'  => 'kiste_id IS NULL AND artikel_fach_id IS NULL AND standort_frei IS NULL',
    default => '1=1',
};

if ($soll === 'unter') {
    $bedingungen[] = 'unter_soll = 1';
} elseif ($soll === 'ueberwacht') {
    $bedingungen[] = 'soll_bestand IS NOT NULL';
}

$where = 'WHERE ' . implode(' AND ', $bedingungen);
$sql   = "SELECT * FROM v_artikel $where ORDER BY {$sortierungen[$sort]}";

$stmt = $pdo->prepare($sql);
$stmt->execute($werte);
$artikel = $stmt->fetchAll();

$kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();

$summe       = array_sum(array_column($artikel, 'menge'));
$summeGesamt = array_sum(array_column($artikel, 'gesamt_einzeln'));
$mitGebinde  = count(array_filter($artikel, fn($a) => (int) $a['hat_gebinde'] === 1));
$unterSoll   = count(array_filter($artikel, fn($a) => (int) $a['unter_soll'] === 1));
$verliehen = array_sum(array_column($artikel, 'verliehen_offen'));

/** Erzeugt einen sortierbaren Spaltenkopf mit Richtungswechsel. */
function spalte(string $schluessel, string $label, string $aktiv): string
{
    $absteigend = $schluessel . '_desc';
    $ziel  = ($aktiv === $schluessel) ? $absteigend : $schluessel;
    $pfeil = match ($aktiv) {
        $schluessel => ' <span class="pfeil">▲</span>',
        $absteigend => ' <span class="pfeil">▼</span>',
        default     => '',
    };
    return '<a href="' . h(url_mit(['sort' => $ziel])) . '">' . h($label) . $pfeil . '</a>';
}

$titel = 'Artikel';
$aktiv = 'index';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Artikel</h1>
  <span class="anzahl">
    <?= count($artikel) ?> Positionen · <?= (int) $summeGesamt ?> Einzelstücke<?php
    if ($mitGebinde > 0) { echo ' · ' . $mitGebinde . ' in Gebinden'; }
    if ($unterSoll > 0) { echo ' · ' . $unterSoll . ' unter Soll'; }
    if ($verliehen > 0) { echo ' · ' . (int) $verliehen . ' verliehen'; } ?>
  </span>
  <span class="rechts">
    <a class="btn" href="gebinde.php">Gebinde</a>
    <a class="btn" href="code.php">Etiketten</a>
    <a class="btn" href="export.php">Export</a>
    <?php if (darf('bearbeiter')): ?><a class="btn btn--primaer" href="artikel.php">Artikel anlegen</a><?php endif; ?>
  </span>
</div>

<form class="filter" method="get" action="index.php">
  <div class="feld feld--breit">
    <label for="q">Suche</label>
    <input type="search" id="q" name="q" value="<?= h($suche) ?>"
           placeholder="Nummer, EAN, Name, Kiste, Standort, Notiz oder Zusatzangabe">
  </div>
  <div class="feld">
    <label for="kat">Kategorie</label>
    <select id="kat" name="kat">
      <option value="">alle</option>
      <?php foreach ($kategorien as $k): ?>
        <option value="<?= (int) $k['kategorie_id'] ?>"
          <?= $katId === (string) $k['kategorie_id'] ? ' selected' : '' ?>>
          <?= h($k['bezeichnung']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="feld">
    <label for="ort">Lagerort</label>
    <select id="ort" name="ort">
      <option value="">alle</option>
      <option value="kiste" <?= $ort === 'kiste' ? 'selected' : '' ?>>in Kisten</option>
      <option value="fach"  <?= $ort === 'fach'  ? 'selected' : '' ?>>direkt im Fach</option>
      <option value="frei"  <?= $ort === 'frei'  ? 'selected' : '' ?>>freier Standort</option>
      <option value="ohne"  <?= $ort === 'ohne'  ? 'selected' : '' ?>>ohne Lagerort</option>
    </select>
  </div>
  <div class="feld">
    <label for="soll">Sollbestand</label>
    <select id="soll" name="soll">
      <option value="">alle</option>
      <option value="unter"      <?= $soll === 'unter'      ? 'selected' : '' ?>>unter Soll</option>
      <option value="ueberwacht" <?= $soll === 'ueberwacht' ? 'selected' : '' ?>>überwacht</option>
    </select>
  </div>
  <div class="feld">
    <label for="sort">Sortierung</label>
    <select id="sort" name="sort">
      <option value="name"     <?= str_starts_with($sort, 'name')     ? 'selected' : '' ?>>Name</option>
      <option value="nummer"   <?= str_starts_with($sort, 'nummer')   ? 'selected' : '' ?>>Inventarnummer</option>
      <option value="platz"    <?= str_starts_with($sort, 'platz')    ? 'selected' : '' ?>>Lagerort</option>
      <option value="kategorie"<?= str_starts_with($sort, 'kategorie')? 'selected' : '' ?>>Kategorie</option>
      <option value="faellig"  <?= str_starts_with($sort, 'faellig')  ? 'selected' : '' ?>>Fälligkeit</option>
    </select>
  </div>
  <button class="btn" type="submit">Anzeigen</button>
  <?php if ($suche !== '' || $katId !== '' || $ort !== '' || $soll !== ''): ?>
    <a class="btn" href="index.php">Filter zurücksetzen</a>
  <?php endif; ?>
</form>

<?php if (!$artikel): ?>
  <p class="leer">
    Keine Artikel gefunden.
    <?php if (darf('bearbeiter')): ?><a href="artikel.php">Artikel anlegen</a><?php endif; ?>
  </p>
<?php else: ?>
<table class="tabelle">
  <thead>
    <tr>
      <th><?= spalte('nummer', 'Nr.', $sort) ?></th>
      <th><?= spalte('name', 'Artikel', $sort) ?></th>
      <th><?= spalte('platz', 'Lagerort', $sort) ?></th>
      <th><?= spalte('kategorie', 'Kategorie', $sort) ?></th>
      <th style="text-align:right"><?= spalte('menge', 'Bestand', $sort) ?></th>
      <th><?= spalte('faellig', 'Fälligkeit', $sort) ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($artikel as $a):
      $tage  = $a['tage_bis_faellig'] === null ? null : (int) $a['tage_bis_faellig'];
      $stufe = faellig_stufe($tage);
      $leihe = (int) $a['verliehen_offen'];
  ?>
    <tr<?= $stufe && $stufe !== 'ok' ? ' class="stufe-' . $stufe . '"' : '' ?>>
      <td data-label="Nr.">
        <span class="invnr"><?= h($a['inventar_nr']) ?></span>
        <?php if ($a['ean']): ?><span class="ean" style="display:block"><?= h($a['ean']) ?></span><?php endif; ?>
      </td>
      <td data-label="Artikel">
        <span class="name"><?= h($a['artikelname']) ?></span>
        <?php if ($a['notiz']): ?><span class="notiz"><?= h($a['notiz']) ?></span><?php endif; ?>
        <?php if ($a['zusatzfelder']): ?><span class="zusatz"><?= h($a['zusatzfelder']) ?></span><?php endif; ?>
      </td>
      <td data-label="Lagerort"><?= lagerort($a) ?></td>
      <td data-label="Kategorie">
        <?php if ($a['kategorie']): ?>
          <span class="kat"><?= h($a['kategorie']) ?></span>
        <?php else: ?>
          <span class="kat kat--leer">ohne</span>
        <?php endif; ?>
      </td>
      <td class="zahl" data-label="Bestand">
        <?php if ($a['hat_gebinde']): ?>
          <span class="best-gebinde"><?= (int) $a['menge'] ?><small><?= h(mehrzahl($a['gebinde_einheit'] ?: 'Gebinde')) ?></small></span>
          <span class="best-gesamt"><?= (int) $a['gesamt_einzeln'] ?> <?= h($a['ve_einheit'] ?: 'Stück') ?><?php
            if ((int) $a['lose'] > 0) { echo ', davon ' . (int) $a['lose'] . ' lose'; } ?></span>
        <?php else: ?>
          <?= (int) $a['menge'] ?>
        <?php endif; ?>
        <?php if ((int) $a['unter_soll'] === 1): ?>
          <span class="fehlt"><?= (int) $a['fehlmenge'] ?> unter Soll</span>
        <?php endif; ?>
        <?php if ($leihe > 0): ?>
          <span class="leihe"><?= $leihe ?> verliehen</span>
        <?php endif; ?>
      </td>
      <td data-label="Fälligkeit">
        <?php if ($a['faellig_am']): ?>
          <span class="faellig stufe-<?= h((string) $stufe) ?>">
            <span class="faellig-datum"><?= h(datum_de($a['faellig_am'])) ?></span>
            <span class="faellig-typ"><?= h(faellig_label($a['faellig_typ'])) ?><?php
              if ($stufe === 'faellig') { echo ' · seit ' . abs((int) $tage) . ' Tagen'; }
              elseif ($stufe === 'bald') { echo ' · in ' . (int) $tage . ' Tagen'; }
            ?></span>
          </span>
          <?php if ((int) $a['anzahl_chargen'] > 1): ?>
            <span class="chargen-text" title="<?= h($a['chargen_text']) ?>">+<?= (int) $a['anzahl_chargen'] - 1 ?> weitere MHD</span>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td class="aktion" data-label="">
        <?php if (darf('bearbeiter')): ?>
        <a class="btn btn--klein" href="zugang.php?artikel=<?= (int) $a['artikel_id'] ?>">Einbuchen</a>
        <a class="btn btn--klein" href="entnahme.php?artikel=<?= (int) $a['artikel_id'] ?>">Entnehmen</a>
        <a class="btn btn--klein" href="artikel.php?id=<?= (int) $a['artikel_id'] ?>">Bearbeiten</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
