<?php
declare(strict_types=1);
/** @var string $titel  Seitentitel, vor dem Einbinden setzen. */
/** @var string $aktiv  Aktiver Navigationspunkt. */
$titel = $titel ?? APP_NAME;
$aktiv = $aktiv ?? '';

/*
 * Die Navigation ist zweigeteilt.
 *
 * Vorn steht, was im Lageralltag gebraucht wird. Alles, was man selten
 * und meist im Sitzen macht – Stammdaten, Import, Benutzer – liegt im
 * Menü "Verwaltung". Zwölf gleichrangige Punkte nebeneinander findet
 * niemand mehr durch, und die täglich genutzten gehen darin unter.
 */
$nav = [
    'index'     => ['Artikel',      'index.php'],
    'scan'      => ['Scannen',      'scan.php'],
    'entnahmen' => ['Bewegungen',   'entnahmen.php'],
    'faellig'   => ['Fälligkeiten', 'faellig.php'],
    'kisten'    => ['Kisten',       'kisten.php'],
    'inventur'  => ['Inventur',     'inventur.php'],
    'export'    => ['Export',       'export.php'],
];

// Verwaltung – je nach Rolle unterschiedlich gefüllt.
$verwaltung = [];
if (darf('bearbeiter')) {
    $verwaltung['kategorien'] = ['Kategorien', 'kategorien.php'];
}
$verwaltung['daten'] = ['Daten', 'daten.php'];   // Export darf jeder
if (darf('admin')) {
    $verwaltung['felder']        = ['Felder',        'felder.php'];
    $verwaltung['benutzer']      = ['Benutzer',      'benutzer.php'];
    $verwaltung['einstellungen'] = ['Einstellungen', 'einstellungen.php'];
}

// Ist gerade eine Verwaltungsseite offen? Dann das Menü hervorheben
// und aufgeklappt zeigen.
$inVerwaltung = isset($verwaltung[$aktiv]);
$ich = benutzer();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titel) ?> – <?= h(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="topbar">
  <a class="brand" href="index.php">
    <span class="brand-mark">LV</span>
    <span class="brand-text"><?= h(APP_NAME) ?></span>
  </a>
  <nav class="nav">
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <a href="<?= h($href) ?>"<?= $aktiv === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>

    <?php if ($verwaltung): ?>
      <!--
        <details> statt eines JavaScript-Menüs: klappt auch ohne Skript
        auf, ist mit der Tastatur bedienbar und braucht keine
        ARIA-Bastelei. Das Skript unten schließt es nur bei Klick
        daneben.
      -->
      <details class="menue<?= $inVerwaltung ? ' is-active' : '' ?>"<?= $inVerwaltung ? ' open' : '' ?>>
        <summary>Verwaltung</summary>
        <div class="menue-liste">
          <?php foreach ($verwaltung as $key => [$label, $href]): ?>
            <a href="<?= h($href) ?>"<?= $aktiv === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </nav>
  <?php if (darf('bearbeiter')): ?>
    <!--
      Der Einsatzknopf sitzt bewusst außerhalb der Navigation und in
      Signalfarbe: im Einsatz zählt, dass jemand mit nassen Händen und
      unter Zeitdruck den Weg zum Buchen findet, ohne die Leiste zu
      lesen. Er ist auf jeder Seite an derselben Stelle.
    -->
    <a class="einsatz" href="entnahme.php" accesskey="e" title="Entnahme buchen (Alt+E)">
      <span class="einsatz-zeichen" aria-hidden="true">−</span>
      <span class="einsatz-text">Entnahme</span>
    </a>
  <?php endif; ?>
  <?php if ($ich): ?>
  <div class="wer">
    <a href="profil.php" class="wer-name" title="Passwort ändern"><?= h($ich['anzeigename']) ?></a>
    <span class="wer-rolle"><?= h(ROLLEN_LABEL[$ich['rolle']]) ?></span>
    <a href="logout.php" class="wer-aus">Abmelden</a>
  </div>
  <?php endif; ?>
</header>

<script>
// Menü schließen, wenn daneben geklickt oder Escape gedrückt wird.
(function () {
  const m = document.querySelector('.menue');
  if (!m) return;
  document.addEventListener('click', e => { if (!m.contains(e.target)) m.open = false; });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') m.open = false; });
})();
</script>

<main class="wrap">
<?php foreach (flash_take() as $f): ?>
  <p class="flash flash--<?= h($f['type']) ?>"><?= h($f['text']) ?></p>
<?php endforeach; ?>
