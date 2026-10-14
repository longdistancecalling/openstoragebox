<?php
declare(strict_types=1);
/** @var string $titel  Seitentitel, vor dem Einbinden setzen. */
/** @var string $aktiv  Aktiver Navigationspunkt. */
$titel = $titel ?? APP_NAME;
$aktiv = $aktiv ?? '';

/*
 * Die Navigation steht in der Seitenleiste und ist zweigeteilt.
 *
 * Oben steht, was im Lageralltag gebraucht wird. Alles, was man selten
 * und meist im Sitzen macht – Stammdaten, Import, Benutzer – steht
 * darunter im Abschnitt "Verwaltung". Zwölf gleichrangige Punkte
 * untereinander findet niemand mehr durch, und die täglich genutzten
 * gehen darin unter.
 *
 * In der Kopfleiste bleiben nur Terminal und Entnahme – die beiden
 * Knöpfe, die im Einsatz sofort gefunden werden müssen.
 */
$nav = [
    'index'     => ['Artikel',      'index.php'],
    'scan'      => ['Scannen',      'scan.php'],
    'entnahmen' => ['Bewegungen',   'entnahmen.php'],
    'bedarf'    => ['Bedarf',       'bedarf.php'],
    'kisten'    => ['Kisten',       'kisten.php'],
    'inventur'  => ['Inventur',     'inventur.php'],
    'export'    => ['Export',       'export.php'],
];

// Verwaltung – je nach Rolle unterschiedlich gefüllt.
$verwaltung = [];
if (darf('bearbeiter')) {
    $verwaltung['orte']       = ['Lagerorte',  'orte.php'];
    $verwaltung['kategorien'] = ['Kategorien', 'kategorien.php'];
}
$verwaltung['daten'] = ['Daten', 'daten.php'];   // Export darf jeder
if (darf('admin')) {
    $verwaltung['felder']        = ['Felder',        'felder.php'];
    $verwaltung['benutzer']      = ['Benutzer',      'benutzer.php'];
    $verwaltung['einstellungen'] = ['Einstellungen', 'einstellungen.php'];
}

// Ist gerade eine Verwaltungsseite offen? Dann den Abschnitt
// aufgeklappt zeigen, sonst bleibt er zu.
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
<!-- Ohne Skript bleibt die Seitenleiste am Handy einfach offen;
     erst mit Skript wird sie zum Klappmenü. -->
<script>document.documentElement.classList.add('js');</script>
</head>
<body>

<header class="topbar">
  <button type="button" class="menue-knopf" aria-controls="seitenleiste" aria-expanded="false">
    <span aria-hidden="true">☰</span> Menü
  </button>
  <a class="brand" href="index.php">
    <span class="brand-mark">OSB</span>
    <span class="brand-text">
      <span class="brand-app"><?= h(APP_NAME) ?></span>
      <?php if (ORGANISATION !== '' && ORGANISATION !== APP_NAME): ?>
        <span class="brand-org"><?= h(ORGANISATION) ?></span>
      <?php endif; ?>
    </span>
  </a>
  <?php if (darf('bearbeiter')): ?>
    <!--
      Terminal und Entnahme stehen als Paar rechts in der Leiste, in
      Signalfarben: im Einsatz zählt, dass jemand mit nassen Händen und
      unter Zeitdruck den Weg zum Buchen findet, ohne die Leiste zu
      lesen. Beide Knöpfe sind auf jeder Seite an derselben Stelle.
      Terminal (blau) öffnet die Buchungsstation fürs Lager-Tablet,
      Entnahme (rot) das normale Entnahmeformular.
    -->
    <div class="kopf-aktionen">
      <a class="terminal-knopf" href="touch.php" title="Buchungsterminal öffnen">
        <span class="terminal-zeichen" aria-hidden="true">▭</span>
        <span class="terminal-text">Terminal</span>
      </a>
      <a class="einsatz" href="entnahme.php" accesskey="e" title="Entnahme buchen (Alt+E)">
        <span class="einsatz-zeichen" aria-hidden="true">−</span>
        <span class="einsatz-text">Entnahme</span>
      </a>
    </div>
  <?php endif; ?>
  <?php if ($ich): ?>
  <div class="wer">
    <a href="profil.php" class="wer-name" title="Passwort ändern"><?= h($ich['anzeigename']) ?></a>
    <span class="wer-rolle"><?= h(ROLLEN_LABEL[$ich['rolle']]) ?></span>
    <a href="logout.php" class="wer-aus">Abmelden</a>
  </div>
  <?php endif; ?>
</header>

<div class="rahmen">
<nav class="seitenleiste" id="seitenleiste" aria-label="Navigation">
  <!-- Die Leiste selbst reicht bis zum Seitenende, die Liste darin
       bleibt beim Scrollen langer Artikellisten oben stehen. -->
  <div class="seitenleiste-liste">
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <a href="<?= h($href) ?>"<?= $aktiv === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>

    <?php if ($verwaltung): ?>
      <!--
        <details> statt eines JavaScript-Menüs: klappt auch ohne Skript
        auf, ist mit der Tastatur bedienbar und braucht keine
        ARIA-Bastelei.
      -->
      <details class="seitenleiste-gruppe"<?= $inVerwaltung ? ' open' : '' ?>>
        <summary class="seitenleiste-titel">Verwaltung</summary>
        <?php foreach ($verwaltung as $key => [$label, $href]): ?>
          <a href="<?= h($href) ?>"<?= $aktiv === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>
  </div>
</nav>

<script>
// Am Handy klappt der Menü-Knopf die Seitenleiste auf und zu.
(function () {
  const k = document.querySelector('.menue-knopf');
  const s = document.getElementById('seitenleiste');
  k.addEventListener('click', () => {
    const offen = s.classList.toggle('is-offen');
    k.setAttribute('aria-expanded', offen ? 'true' : 'false');
  });
})();
</script>

<div class="inhalt">
<main class="wrap">
<?php foreach (flash_take() as $f): ?>
  <p class="flash flash--<?= h($f['type']) ?>"><?= h($f['text']) ?></p>
<?php endforeach; ?>
