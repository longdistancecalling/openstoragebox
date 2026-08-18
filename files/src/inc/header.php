<?php
declare(strict_types=1);
/** @var string $titel  Seitentitel, vor dem Einbinden setzen. */
/** @var string $aktiv  Aktiver Navigationspunkt. */
$titel = $titel ?? APP_NAME;
$aktiv = $aktiv ?? '';

$nav = [
    'index'      => ['Artikel',     'index.php'],
    'entnahmen'  => ['Entnahmen',   'entnahmen.php'],
    'faellig'    => ['Fälligkeiten','faellig.php'],
    'kisten'     => ['Kisten',      'kisten.php'],
    'inventur'   => ['Inventur',    'inventur.php'],
    'export'     => ['Export',      'export.php'],
    'kategorien' => ['Kategorien',  'kategorien.php'],
];
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
    <span class="brand-mark">Bereitschaft EA1</span>
    <span class="brand-text"><?= h(APP_NAME) ?></span>
  </a>
  <nav class="nav">
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <a href="<?= h($href) ?>"<?= $aktiv === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main class="wrap">
<?php foreach (flash_take() as $f): ?>
  <p class="flash flash--<?= h($f['type']) ?>"><?= h($f['text']) ?></p>
<?php endforeach; ?>
