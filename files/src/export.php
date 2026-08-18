<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';

$was    = (string) ($_GET['was'] ?? '');
$format = (string) ($_GET['format'] ?? 'html');

/* ===================================================================
   CSV-Ausgaben – laufen vor jeder HTML-Ausgabe, weil sie eigene
   Header senden.
   =================================================================== */
if ($format === 'csv') {

    if ($was === 'artikel') {
        $zeilen = $pdo->query(
            'SELECT inventar_nr, artikelname, IFNULL(kiste_nr, "") AS kiste,
                    IFNULL(lagerort_text, "ohne Lagerort") AS lagerort,
                    IFNULL(kategorie, "") AS kategorie, menge,
                    IFNULL(faellig_am, "") AS faellig_am, IFNULL(faellig_typ, "") AS faellig_typ
             FROM v_artikel ORDER BY artikelname, inventar_nr'
        )->fetchAll();

        $daten = array_map(static fn($z) => [
            $z['inventar_nr'], $z['artikelname'], $z['kiste'], $z['lagerort'],
            $z['kategorie'], $z['menge'],
            $z['faellig_am'] ? datum_de($z['faellig_am']) : '',
            faellig_label($z['faellig_typ'] ?: null),
        ], $zeilen);

        csv_ausgeben(
            'artikelliste_' . date('Y-m-d') . '.csv',
            ['Nummer', 'Artikel', 'Kiste', 'Lagerort', 'Kategorie', 'Bestand', 'Fällig am', 'Art'],
            $daten
        );
    }

    if ($was === 'entnahmen') {
        $grund = (string) ($_GET['grund'] ?? '');
        $sql   = 'SELECT e.entnommen_am, a.inventar_nr, a.artikelname, e.menge, e.grund,
                         IFNULL(e.person, "") AS person, IFNULL(e.bezug, "") AS bezug,
                         IFNULL(e.notiz, "") AS notiz, IFNULL(e.zurueck_am, "") AS zurueck_am
                  FROM entnahme e JOIN artikel a ON a.artikel_id = e.artikel_id';
        $werte = [];
        if (isset(ENTNAHME_GRUENDE[$grund])) {
            $sql .= ' WHERE e.grund = :grund';
            $werte[':grund'] = $grund;
        }
        $sql .= ' ORDER BY e.entnommen_am DESC, e.entnahme_id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($werte);

        $daten = array_map(static fn($z) => [
            datum_de($z['entnommen_am']), $z['inventar_nr'], $z['artikelname'],
            $z['menge'], grund_label($z['grund']), $z['person'], $z['bezug'], $z['notiz'],
            $z['zurueck_am'] ? datum_de($z['zurueck_am']) : '',
        ], $stmt->fetchAll());

        csv_ausgeben(
            'entnahmen_' . date('Y-m-d') . '.csv',
            ['Datum', 'Nummer', 'Artikel', 'Menge', 'Grund', 'Person', 'Bezug', 'Notiz', 'Zurück am'],
            $daten
        );
    }

    if ($was === 'kisten') {
        $zeilen = $pdo->query(
            'SELECT k.kiste_nr, k.lager, k.regal, k.fach, IFNULL(k.notiz, "") AS notiz,
                    COUNT(a.artikel_id) AS positionen, COALESCE(SUM(a.menge), 0) AS stueck
             FROM kiste k LEFT JOIN artikel a ON a.kiste_id = k.kiste_id
             GROUP BY k.kiste_id ORDER BY ' . str_replace(
                 ['lager', 'regal', 'fach', 'kiste_nr'],
                 ['k.lager', 'k.regal', 'k.fach', 'k.kiste_nr'],
                 ORDER_LAGERPLATZ
             )
        )->fetchAll();

        csv_ausgeben(
            'kisten_' . date('Y-m-d') . '.csv',
            ['Kiste', 'Lager', 'Regal', 'Fach', 'Notiz', 'Positionen', 'Stück'],
            array_map('array_values', $zeilen)
        );
    }

    // Kisteninhalt: eine Zeile je Artikel, nach Kiste gruppiert.
    // Das Gegenstück zum gedruckten Etikett, aber weiterverarbeitbar.
    if ($was === 'kisteninhalt') {
        $zeilen = $pdo->query(
            'SELECT k.kiste_nr, k.lager, k.regal, k.fach,
                    IFNULL(a.inventar_nr, "") AS inventar_nr,
                    IFNULL(a.artikelname, "(leer)") AS artikelname,
                    IFNULL(a.menge, "") AS menge,
                    IFNULL(kat.bezeichnung, "") AS kategorie,
                    IFNULL(a.faellig_am, "") AS faellig_am,
                    IFNULL(a.faellig_typ, "") AS faellig_typ
             FROM kiste k
             LEFT JOIN artikel a   ON a.kiste_id = k.kiste_id
             LEFT JOIN kategorie kat ON kat.kategorie_id = a.kategorie_id
             ORDER BY ' . str_replace(
                 ['lager', 'regal', 'fach', 'kiste_nr'],
                 ['k.lager', 'k.regal', 'k.fach', 'k.kiste_nr'],
                 ORDER_LAGERPLATZ
             ) . ', a.artikelname'
        )->fetchAll();

        $daten = array_map(static fn($z) => [
            $z['kiste_nr'], $z['lager'], $z['regal'], $z['fach'],
            $z['inventar_nr'], $z['artikelname'], $z['menge'], $z['kategorie'],
            $z['faellig_am'] ? datum_de($z['faellig_am']) : '',
            faellig_label($z['faellig_typ'] ?: null),
        ], $zeilen);

        csv_ausgeben(
            'kisteninhalt_' . date('Y-m-d') . '.csv',
            ['Kiste', 'Lager', 'Regal', 'Fach', 'Nummer', 'Artikel', 'Menge',
             'Kategorie', 'Fällig am', 'Art'],
            $daten
        );
    }
}

/* ===================================================================
   Druckansicht: Artikelliste alphabetisch
   =================================================================== */
if ($was === 'artikel' && $format === 'druck') {
    $zeilen = $pdo->query(
        'SELECT inventar_nr, artikelname, kiste_nr, lagerort_text, menge
         FROM v_artikel ORDER BY artikelname, inventar_nr'
    )->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
      <meta charset="utf-8">
      <title>Artikelliste <?= date('d.m.Y') ?></title>
      <link rel="stylesheet" href="assets/style.css">
      <link rel="stylesheet" href="assets/druck.css">
    </head>
    <body class="druck druck--liste">
      <div class="druck-kopf">
        <div>
          <div class="druck-org"><?= h(ORGANISATION) ?></div>
          <h1>Artikelliste</h1>
        </div>
        <span><?= count($zeilen) ?> Positionen · Stand <?= date('d.m.Y') ?></span>
      </div>
      <table class="druck-tabelle">
        <thead>
          <tr><th>Nr.</th><th>Artikel</th><th>Kiste</th><th>Lagerort</th><th class="r">Bestand</th></tr>
        </thead>
        <tbody>
        <?php foreach ($zeilen as $z): ?>
          <tr>
            <td class="mono"><?= h($z['inventar_nr']) ?></td>
            <td><?= h($z['artikelname']) ?></td>
            <td class="mono"><?= h($z['kiste_nr'] ?? '—') ?></td>
            <td><?= h($z['lagerort_text'] ?? 'ohne Lagerort') ?></td>
            <td class="r mono"><?= (int) $z['menge'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nur-bildschirm"><a class="btn" href="export.php">Zurück</a>
         <button class="btn btn--primaer" onclick="window.print()">Drucken</button></p>
    </body>
    </html>
    <?php
    exit;
}

/* ===================================================================
   Übersicht
   =================================================================== */
$anzArtikel = (int) $pdo->query('SELECT COUNT(*) FROM artikel')->fetchColumn();
$anzKisten  = (int) $pdo->query('SELECT COUNT(*) FROM kiste')->fetchColumn();
$anzEntn    = (int) $pdo->query('SELECT COUNT(*) FROM entnahme')->fetchColumn();

$titel = 'Export';
$aktiv = 'export';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Export</h1>
</div>

<div class="kacheln">

  <section class="kachel">
    <h2>Artikelliste</h2>
    <p>Alphabetisch nach Name, mit Inventarnummer, Kiste und Lagerort.
       <?= $anzArtikel ?> Positionen.</p>
    <div class="kachel-knoepfe">
      <a class="btn btn--primaer" href="export.php?was=artikel&amp;format=druck">Druckansicht</a>
      <a class="btn" href="export.php?was=artikel&amp;format=csv">CSV</a>
    </div>
  </section>

  <section class="kachel">
    <h2>Kistenetiketten A5</h2>
    <p>Eine A5-Seite je Kiste mit Nummer, Lagerort und vollständigem Inhalt.
       Zum Aufkleben oder Einlegen. <?= $anzKisten ?> Kisten.</p>
    <div class="kachel-knoepfe">
      <a class="btn btn--primaer" href="etikett.php">Alle Kisten</a>
      <a class="btn" href="kisten.php">Einzelne Kiste</a>
    </div>
  </section>

  <section class="kachel">
    <h2>Entnahmeprotokoll</h2>
    <p>Alle Buchungen mit Grund, Person und Rückgabestatus.
       <?= $anzEntn ?> Einträge.</p>
    <div class="kachel-knoepfe">
      <a class="btn" href="export.php?was=entnahmen&amp;format=csv">CSV</a>
      <a class="btn" href="entnahmen.php">Ansehen</a>
    </div>
  </section>

  <section class="kachel">
    <h2>Kistenverzeichnis</h2>
    <p>Alle Kisten mit Lagerort und Belegung, eine Zeile je Kiste.</p>
    <div class="kachel-knoepfe">
      <a class="btn" href="export.php?was=kisten&amp;format=csv">CSV</a>
    </div>
  </section>

  <section class="kachel">
    <h2>Kisteninhalt</h2>
    <p>Eine Zeile je Artikel, nach Kiste gruppiert – dasselbe wie auf den
       Etiketten, nur weiterverarbeitbar.</p>
    <div class="kachel-knoepfe">
      <a class="btn" href="export.php?was=kisteninhalt&amp;format=csv">CSV</a>
    </div>
  </section>

  <section class="kachel">
    <h2>Inventur</h2>
    <p>Zählliste zum Ausdrucken und Differenzbericht nach der Zählung.</p>
    <div class="kachel-knoepfe">
      <a class="btn" href="inventur.php">Zur Inventur</a>
    </div>
  </section>

</div>

<p class="hinweis" style="margin-top:18px">
  Die CSV-Dateien nutzen Semikolon als Trenner und beginnen mit einem UTF-8-BOM.
  Damit öffnet Excel sie direkt in Spalten und mit korrekten Umlauten.
</p>

<?php require __DIR__ . '/inc/footer.php'; ?>
