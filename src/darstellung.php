<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';

/*
 * Darstellung – Farben und Schrift für dieses Gerät.
 *
 * Zwei Aufgaben:
 *   mit ?farbe=… oder ?schrift=…  speichern und zurückführen (&zurueck=…)
 *                                 – so arbeitet der Hell/Dunkel-Schalter
 *   ohne Parameter                die Seite unter Verwaltung → Darstellung
 *
 * Bewusst pro Gerät (Cookie) statt pro Benutzerkonto: so gilt die Wahl
 * schon auf der Anmeldeseite, und ein Terminal-Tablet behält seine
 * Darstellung, egal wer gerade angemeldet ist. Ohne Anmeldung
 * erreichbar (OHNE_LOGIN), damit der Schalter auf der Anmeldeseite
 * funktioniert – die Seite selbst verlangt eine Anmeldung.
 */

$d = darstellung();

/* ===================================================================
   Speichern
   =================================================================== */
if (isset($_GET['farbe']) || isset($_GET['schrift'])) {
    if (isset(DARSTELLUNG_FARBEN[$_GET['farbe'] ?? ''])) {
        $d['farbe'] = $_GET['farbe'];
    }
    if (isset(DARSTELLUNG_SCHRIFTEN[$_GET['schrift'] ?? ''])) {
        $d['schrift'] = $_GET['schrift'];
    }

    setcookie(DARSTELLUNG_COOKIE, $d['farbe'] . '.' . $d['schrift'], [
        'expires'  => time() + 365 * 24 * 3600,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
    ]);

    // Nur relative Ziele innerhalb der Anwendung – wie nach der Anmeldung.
    $ziel = ltrim((string) ($_GET['zurueck'] ?? ''), '/');
    if (!preg_match('#^[a-z_]+\.php(\?.*)?$#i', $ziel)) {
        $ziel = startseite();
    }
    redirect($ziel);
}


/* ===================================================================
   Seite
   =================================================================== */
if (!angemeldet()) {
    redirect('login.php');
}

/*
 * Die Farbdesigns in Gruppen – sechzehn Karten untereinander fände
 * niemand mehr durch. Aufgeklappt ist nur die Gruppe mit dem aktiven
 * Design; die Gruppenzeile zeigt auch zugeklappt Farbpunkte ihrer
 * Designs. Ein Design ohne Gruppe landet unter "Weitere", damit ein
 * neues nicht unsichtbar bleibt.
 */
$gruppen = [
    'standard'       => ['Standard', 'Das Gewohnte, hell oder dunkel.', ['hell', 'dunkel']],
    'barrierefrei'   => ['Barrierefrei', 'Für Sehschwäche, pralles Sonnenlicht und Rot-Grün-Schwäche.', ['kontrast', 'rotgruen']],
    'lagen'          => ['Besondere Lagen', 'Für Nachteinsätze und E-Paper-Displays.', ['nachtsicht', 'eink']],
    'organisationen' => ['Hilfsorganisationen', 'Farben und Markierungen aus dem Einsatzalltag.', ['rotesplus', 'rettungsdienst', 'feuerwehr', 'thw', 'wasserwacht']],
    'stimmungen'     => ['Stimmungen', 'Zum Wohlfühlen – oder einfach zum Spaß.', ['wald', 'lagerbuch', 'blaupause', 'phosphor', 'warnweste']],
];
foreach ($gruppen as &$g) {
    $g[2] = array_values(array_filter($g[2], fn($w) => isset(DARSTELLUNG_FARBEN[$w])));
}
unset($g);
$ohneGruppe = array_values(array_diff(array_keys(DARSTELLUNG_FARBEN), array_merge(...array_column($gruppen, 2))));
if ($ohneGruppe) {
    $gruppen['weitere'] = ['Weitere', '', $ohneGruppe];
}

// Kurz gehalten: je Karte höchstens zwei Zeilen.
$farbText = [
    'hell'           => 'Das Standard-Design.',
    'dunkel'         => 'Helle Schrift auf dunklem Grund. Auch über den Schalter in der Kopfleiste.',
    'kontrast'       => 'Schwarz auf Weiß, gelbe Hervorhebung, kräftige Rahmen.',
    'rotgruen'       => 'Warnungen in Orange, „in Ordnung“ in Blau.',
    'nachtsicht'     => 'Nur rotes Licht – stört die Dunkelanpassung der Augen am wenigsten.',
    'eink'           => 'Nur Schwarz, Weiß und Grau, ohne Bewegung.',
    'rotesplus'      => 'Viel Weiß, rote Überschriften mit Serifen, Links in Dunkelblau.',
    'rettungsdienst' => 'Weiß wie ein Rettungswagen, Schachbrettband in Rot und Gelb.',
    'feuerwehr'      => 'Feuerrot (RAL 3000), Warnband in Rot und Gelb, Nachtblau.',
    'thw'            => 'Blau (RAL 5002) mit blau-weißem Warnband, Orange als Akzent.',
    'wasserwacht'    => 'Wasserblau und Weiß mit Wellenkante.',
    'wald'           => 'Salbeigrün, Tanne und Moos, ein Hauch Sonnenflecken.',
    'lagerbuch'      => 'Liniertes Papier, braune Tinte, Füllerblau, Stempelrot.',
    'blaupause'      => 'Bauplan-Blau mit Millimeterraster und Schablonenschrift.',
    'phosphor'       => 'Grüner Monitor mit Bildschirmzeilen und blinkendem Cursor.',
    'warnweste'      => 'Leuchtgelb mit Reflexstreifen – auffällig, eher fürs Auge.',
];
$schriftFamilie = [
    'standard'     => '"IBM Plex Sans", sans-serif',
    'opendyslexic' => '"OpenDyslexic", sans-serif',
    'lexend'       => '"Lexend", sans-serif',
];
$schriftText = [
    'standard'     => 'IBM Plex Sans, die Standardschrift.',
    'opendyslexic' => 'Für LRS: unten beschwerte Buchstaben, schwerer zu verwechseln (b/d, p/q).',
    'lexend'       => 'Ruhige, weit laufende Schrift für flüssigeres Lesen.',
];

/**
 * Ein Stück Oberfläche im Design $farbe. Der Rahmen setzt zuerst auf
 * Hell zurück, damit Designs, die nur einzelne Farben ändern, nicht vom
 * gerade aktiven erben.
 */
function vorschau(string $farbe): void
{
    ?>
    <span class="vorschau-rahmen" data-farbe="hell" aria-hidden="true">
      <span class="vorschau" data-farbe="<?= h($farbe) ?>">
        <span class="vorschau-kopf">
          <span class="vorschau-marke">OSB</span> Lager
          <span class="vorschau-knopf">Terminal</span>
        </span>
        <span class="vorschau-flaeche">
          <span class="vorschau-zeile">Mineralwasser <small>16 Kisten</small></span>
          <span class="vorschau-marken">
            <span class="marke marke--rot">abgelaufen</span>
            <span class="marke marke--amber">bald</span>
            <span class="marke marke--gruen">zurück</span>
          </span>
        </span>
      </span>
    </span>
    <?php
}

$titel = 'Darstellung';
$aktiv = 'darstellung';
require __DIR__ . '/inc/header.php';
?>
<!-- Für die Schriftprobe; sonst lädt Lexend nur, wenn es gewählt ist. -->
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet">

<div class="kopf">
  <h1>Darstellung</h1>
  <span class="anzahl">gilt für dieses Gerät</span>
</div>

<!-- Aktuell: auf einen Blick, was eingestellt ist – und der Weg zurück. -->
<div class="darstellung-aktuell">
  <span class="darstellung-aktuell-vorschau"><?php vorschau($d['farbe']); ?></span>
  <div class="darstellung-aktuell-text">
    <strong>Aktuell:</strong>
    Farben <b><?= h(DARSTELLUNG_FARBEN[$d['farbe']]) ?></b> ·
    Schrift <b><?= h(DARSTELLUNG_SCHRIFTEN[$d['schrift']]) ?></b>
    <span class="hinweis">
      Die Wahl gilt für alle, die dieses Gerät benutzen. Hell/Dunkel geht auch über den Schalter
      oben in der Kopfleiste. Ausdrucke bleiben immer schwarz auf weiß.
    </span>
  </div>
  <?php if ($d['farbe'] !== 'hell' || $d['schrift'] !== 'standard'): ?>
    <a class="btn" href="<?= h(darstellung_link(['farbe' => 'hell', 'schrift' => 'standard'])) ?>">Auf Standard zurücksetzen</a>
  <?php endif; ?>
</div>

<div class="darstellung-zeile">
  <h2 class="abschnitt">Farben</h2>
  <button type="button" class="btn btn--klein" id="gruppen-schalter" hidden>Alle aufklappen</button>
</div>

<?php foreach ($gruppen as $key => [$gTitel, $gText, $werte]):
    $offen = in_array($d['farbe'], $werte, true);
?>
  <details class="darstellung-gruppe"<?= $offen ? ' open' : '' ?>>
    <summary>
      <span class="darstellung-gruppe-titel"><?= h($gTitel) ?></span>
      <span class="darstellung-gruppe-anzahl"><?= count($werte) ?></span>
      <span class="darstellung-gruppe-punkte" aria-hidden="true">
        <?php foreach ($werte as $w): ?>
          <span class="punkt-rahmen" data-farbe="hell"><span class="farbpunkt" data-farbe="<?= h($w) ?>"></span></span>
        <?php endforeach; ?>
      </span>
      <?php if ($offen): ?>
        <span class="darstellung-gruppe-aktiv">aktiv: <?= h(DARSTELLUNG_FARBEN[$d['farbe']]) ?></span>
      <?php endif; ?>
      <?php if ($gText !== ''): ?>
        <span class="darstellung-gruppe-text"><?= h($gText) ?></span>
      <?php endif; ?>
    </summary>
    <div class="darstellung-wahl">
      <?php foreach ($werte as $wert): ?>
        <a class="darstellung-karte<?= $d['farbe'] === $wert ? ' is-aktiv' : '' ?>"
           href="<?= h(darstellung_link(['farbe' => $wert])) ?>"
           <?= $d['farbe'] === $wert ? 'aria-current="true"' : '' ?>>
          <span class="darstellung-karte-titel"><?= h(DARSTELLUNG_FARBEN[$wert]) ?></span>
          <?php vorschau($wert); ?>
          <span class="darstellung-karte-text"><?= h($farbText[$wert] ?? '') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>

<h2 class="abschnitt">Schrift</h2>
<div class="darstellung-wahl">
  <?php foreach (DARSTELLUNG_SCHRIFTEN as $wert => $label): ?>
    <a class="darstellung-karte<?= $d['schrift'] === $wert ? ' is-aktiv' : '' ?>"
       href="<?= h(darstellung_link(['schrift' => $wert])) ?>"
       <?= $d['schrift'] === $wert ? 'aria-current="true"' : '' ?>>
      <span class="darstellung-karte-titel"><?= h($label) ?></span>
      <span class="schriftprobe" style="font-family:<?= h($schriftFamilie[$wert]) ?>">
        Mineralwasser still 0,5 l<br>MHD 15.11.2026 · 24 Flaschen
      </span>
      <span class="darstellung-karte-text"><?= h($schriftText[$wert]) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<script>
// "Alle aufklappen / zuklappen" – ohne Skript bleibt der Knopf verborgen,
// die Gruppen lassen sich dann einzeln öffnen.
(function () {
  const knopf = document.getElementById('gruppen-schalter');
  const gruppen = [...document.querySelectorAll('.darstellung-gruppe')];
  if (!knopf || !gruppen.length) return;
  knopf.hidden = false;
  const beschriften = () => {
    knopf.textContent = gruppen.every(g => g.open) ? 'Alle zuklappen' : 'Alle aufklappen';
  };
  knopf.addEventListener('click', () => {
    const auf = !gruppen.every(g => g.open);
    gruppen.forEach(g => { g.open = auf; });
    beschriften();
  });
  gruppen.forEach(g => g.addEventListener('toggle', beschriften));
  beschriften();
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
