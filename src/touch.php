<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Buchungsterminal für ein Tablet an der Wand.
 *
 * Reduziert auf einen einzigen Ablauf: scannen, große Zahl eingeben,
 * mit einem breiten Knopf entnehmen oder einbuchen. Alles ist auf
 * fingerbedienbare Größen ausgelegt (Knöpfe ab 64 px), ohne
 * Kleingedrucktes und ohne Navigation, damit im Einsatz niemand sucht.
 *
 * Die eigentliche Buchung nutzt dieselben Funktionen wie die normale
 * Scan-Seite (bestand_rechnen, code_deuten aus helpers.php) – hier ist
 * nur die Oberfläche eine andere, nicht die Logik.
 *
 * USB-Handscanner: verhält sich wie eine Tastatur, deshalb genügt ein
 * Textfeld mit Dauerfokus. Keine Kamera.
 */

$ich    = benutzer();
$fehler = null;
$erfolg = null;

/* ===================================================================
   Buchung
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    csrf_check();

    $artikelId = ctype_digit((string) ($_POST['artikel_id'] ?? '')) ? (int) $_POST['artikel_id'] : 0;
    $richtung  = (string) ($_POST['richtung'] ?? '');
    $menge     = (string) ($_POST['menge'] ?? '1');
    $einheitIn = (string) ($_POST['einheit'] ?? 'gebinde');

    if (!in_array($richtung, ['zugang', 'abgang'], true)) {
        $fehler = 'Unbekannte Buchungsrichtung.';
    } elseif (!ctype_digit($menge) || (int) $menge < 1) {
        $fehler = 'Bitte eine Menge ab 1 eingeben.';
    } else {
        $menge = (int) $menge;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT artikelname, menge, lose, ve_inhalt, ve_einheit, gebinde_einheit
                 FROM artikel WHERE artikel_id = ? FOR UPDATE'
            );
            $stmt->execute([$artikelId]);
            $art = $stmt->fetch();
            if (!$art) {
                throw new RuntimeException('Dieser Artikel existiert nicht.');
            }

            $einheit = empty($art['ve_inhalt']) ? 'einzeln' : $einheitIn;
            [$neueMenge, $neueLose] = bestand_rechnen($art, $menge, $einheit, $richtung === 'zugang');

            $pdo->prepare('UPDATE artikel SET menge = ?, lose = ? WHERE artikel_id = ?')
                ->execute([$neueMenge, $neueLose, $artikelId]);

            // Terminal bucht ausschließlich Einsatz-Abgänge und
            // Rücklauf-Zugänge – die beiden Fälle, die im Lager unter
            // Zeitdruck vorkommen. Alles andere läuft über die normale
            // Oberfläche, wo es einen Grund auszuwählen gibt.
            if ($richtung === 'abgang') {
                $pdo->prepare(
                    'INSERT INTO entnahme (artikel_id, menge, einheit, grund, entnommen_am, person, notiz, erfasst_von)
                     VALUES (?, ?, ?, "einsatz", CURDATE(), ?, "am Terminal gebucht", ?)'
                )->execute([$artikelId, $menge, $einheit, $ich['anzeigename'], (int) $ich['benutzer_id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO zugang (artikel_id, menge, einheit, grund, datum, person, notiz, erfasst_von)
                     VALUES (?, ?, ?, "ruecklauf", CURDATE(), ?, "am Terminal gebucht", ?)'
                )->execute([$artikelId, $menge, $einheit, $ich['anzeigename'], (int) $ich['benutzer_id']]);
            }
            $pdo->commit();

            $art['menge'] = $neueMenge;
            $art['lose']  = $neueLose;
            $art['gesamt_einzeln'] = $neueMenge * (int) ($art['ve_inhalt'] ?? 1) + $neueLose;

            $erfolg = [
                'richtung' => $richtung,
                'menge'    => einheit_text($art, $einheit, $menge),
                'name'     => $art['artikelname'],
                'stand'    => bestand_text($art),
            ];
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $fehler = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Terminal-Buchung: ' . $e->getMessage());
            $fehler = 'Die Buchung konnte nicht gespeichert werden.';
        }
    }
}

/* ===================================================================
   Artikel zum Code suchen
   =================================================================== */
$eingabe = trim((string) ($_POST['code'] ?? $_GET['c'] ?? $_GET['q'] ?? ''));
$code    = $eingabe !== '' ? code_deuten($eingabe) : ['art' => 'leer', 'wert' => ''];
$treffer = [];
$kiste   = null;

// Kommt die Eingabe aus dem Suchfeld (q) statt vom Scanner (c), immer als
// Text suchen – auch wenn sie zufällig wie eine Kennung aussieht.
$istSuche = isset($_GET['q']) && trim((string) $_GET['q']) !== '';
if ($istSuche) {
    $code = ['art' => 'suche', 'wert' => $eingabe];
}

if ($code['art'] === 'kiste') {
    $stmt = $pdo->prepare('SELECT * FROM v_kiste WHERE kiste_nr = ?');
    $stmt->execute([$code['wert']]);
    $kiste = $stmt->fetch() ?: null;
    if ($kiste) {
        $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE kiste_id = ? ORDER BY artikelname');
        $stmt->execute([(int) $kiste['kiste_id']]);
        $treffer = $stmt->fetchAll();
    }
} elseif ($code['art'] === 'artikel') {
    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE inventar_nr = ?');
    $stmt->execute([$code['wert']]);
    $treffer = $stmt->fetchAll();
} elseif ($code['art'] === 'ean') {
    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE ean = ? ORDER BY ' . ORDER_LAGERORT);
    $stmt->execute([$code['wert']]);
    $treffer = $stmt->fetchAll();

} elseif ($code['art'] === 'suche') {
    // Volltextsuche über Name, Nummer, EAN, Kiste, Lagerort und Kategorie.
    // Ein Platzhalter, deshalb die Spalten zusammengefasst.
    $stmt = $pdo->prepare(
        "SELECT * FROM v_artikel
          WHERE CONCAT_WS(' ', inventar_nr, IFNULL(ean,''), artikelname,
                          IFNULL(kiste_nr,''), IFNULL(lagerort_text,''),
                          IFNULL(kategorie,'')) LIKE ?
          ORDER BY artikelname LIMIT 30"
    );
    $stmt->execute(['%' . $eingabe . '%']);
    $treffer = $stmt->fetchAll();
}

// Bei genau einem Treffer direkt in die Buchungsansicht.
$aktiv_artikel = (count($treffer) === 1 && $code['art'] !== 'kiste') ? $treffer[0] : null;

/** Ein großer Buchungsblock für einen Artikel. */
function terminal_artikel(array $a, bool $kompakt = false): void
{
    $gebinde = (int) $a['hat_gebinde'] === 1;
    $csrf = csrf_field();
    ?>
    <div class="t-artikel<?= $kompakt ? ' t-artikel--kompakt' : '' ?>">
      <div class="t-kopf">
        <div class="t-name"><?= h($a['artikelname']) ?></div>
        <div class="t-meta">
          <span class="t-nr"><?= h($a['inventar_nr']) ?></span>
          <?php if ($a['lagerort_text']): ?><span class="t-ort"><?= h($a['lagerort_text']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="t-bestand">
        <span class="t-bestand-zahl"><?= (int) ($gebinde ? $a['menge'] : $a['gesamt_einzeln']) ?></span>
        <span class="t-bestand-txt"><?= h(bestand_text($a)) ?></span>
      </div>

      <form method="post" action="touch.php" class="t-form" data-gebinde="<?= $gebinde ? '1' : '0' ?>">
        <?= $csrf ?>
        <input type="hidden" name="aktion" value="buchen">
        <input type="hidden" name="artikel_id" value="<?= (int) $a['artikel_id'] ?>">

        <div class="t-mengenzeile">
          <button type="button" class="t-step" data-step="-1" aria-label="weniger">−</button>
          <input type="number" name="menge" value="1" min="1" step="1" inputmode="numeric"
                 class="t-menge" aria-label="Menge">
          <button type="button" class="t-step" data-step="1" aria-label="mehr">+</button>

          <?php if ($gebinde): ?>
            <select name="einheit" class="t-einheit" aria-label="Einheit">
              <option value="gebinde"><?= h(mehrzahl($a['gebinde_einheit'] ?: 'Gebinde')) ?></option>
              <option value="einzeln"><?= h($a['ve_einheit'] ?: 'Stück') ?></option>
            </select>
          <?php else: ?>
            <input type="hidden" name="einheit" value="einzeln">
          <?php endif; ?>
        </div>

        <div class="t-knoepfe">
          <button type="submit" name="richtung" value="abgang" class="t-btn t-btn--raus"
                  <?= (int) $a['gesamt_einzeln'] === 0 ? 'disabled' : '' ?>>
            <span class="t-btn-zeichen">−</span> Entnehmen
          </button>
          <button type="submit" name="richtung" value="zugang" class="t-btn t-btn--rein">
            <span class="t-btn-zeichen">+</span> Einbuchen
          </button>
        </div>
      </form>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Terminal – <?= h(ORGANISATION) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/touch.css">
</head>
<body class="t-body">

<header class="t-topbar">
  <a class="t-marke" href="touch.php"><?= h(ORGANISATION) ?> · Terminal</a>
  <div class="t-topbar-rechts">
    <span class="t-wer"><?= h($ich['anzeigename']) ?></span>
    <a class="t-verlassen" href="index.php">Vollansicht</a>
  </div>
</header>

<main class="t-main">

  <?php if ($erfolg): ?>
    <div class="t-quittung t-quittung--<?= $erfolg['richtung'] === 'abgang' ? 'raus' : 'rein' ?>">
      <div class="t-quittung-zeichen"><?= $erfolg['richtung'] === 'abgang' ? '−' : '+' ?></div>
      <div class="t-quittung-text">
        <strong><?= h($erfolg['menge']) ?></strong> <?= h($erfolg['name']) ?>
        <?= $erfolg['richtung'] === 'abgang' ? 'entnommen' : 'eingebucht' ?>
      </div>
      <div class="t-quittung-stand">Neuer Bestand: <?= h($erfolg['stand']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($fehler): ?>
    <div class="t-fehler"><?= h($fehler) ?></div>
  <?php endif; ?>

  <div class="t-eingabe">
    <form method="get" action="touch.php" class="t-scan">
      <input type="text" name="c" id="t-scan-feld" value="" autofocus autocomplete="off"
             inputmode="none" placeholder="Barcode scannen …">
      <button type="submit" class="t-scan-btn">Los</button>
    </form>
    <form method="get" action="touch.php" class="t-suche">
      <input type="text" name="q" id="t-suche-feld" value="<?= $istSuche ? h($eingabe) : '' ?>"
             autocomplete="off" placeholder="oder Namen eingeben …">
      <button type="submit" class="t-suche-btn">Suchen</button>
    </form>
  </div>

  <?php if ($aktiv_artikel): ?>
    <?php terminal_artikel($aktiv_artikel); ?>

  <?php elseif ($code['art'] === 'kiste' && $kiste): ?>
    <div class="t-kistenkopf">
      <span class="t-kiste-nr"><?= h($kiste['kiste_nr']) ?></span>
      <span class="t-kiste-ort"><?= h($kiste['lager'] . ' / ' . $kiste['regal'] . ' / ' . $kiste['fach']) ?></span>
      <span class="t-kiste-zahl"><?= count($treffer) ?> Positionen</span>
    </div>
    <?php if (!$treffer): ?>
      <p class="t-leer">Diese Kiste ist leer.</p>
    <?php else: ?>
      <?php foreach ($treffer as $a) { terminal_artikel($a, true); } ?>
    <?php endif; ?>

  <?php elseif (count($treffer) > 1): ?>
    <p class="t-hinweis">
      <?= count($treffer) ?> Artikel<?= $code['art'] === 'suche' ? ' zu „' . h($eingabe) . '“' : '' ?>
      – bitte auswählen:
    </p>
    <?php foreach ($treffer as $a) { terminal_artikel($a, true); } ?>

  <?php elseif ($eingabe !== ''): ?>
    <div class="t-nichts">
      <div class="t-nichts-zeichen">?</div>
      <div>Kein Artikel zu <strong><?= h($eingabe) ?></strong> gefunden.</div>
      <div class="t-nichts-sub">
        <?= $code['art'] === 'suche' ? 'Anderen Suchbegriff versuchen' : 'Nochmal scannen oder unten nach dem Namen suchen' ?>.
      </div>
    </div>

  <?php else: ?>
    <div class="t-start">
      <div class="t-start-zeichen">⤒</div>
      <div class="t-start-text">Barcode scannen, um zu buchen</div>
      <div class="t-start-sub">
        Kiste, Artikel oder Herstellercode – oder unten den Namen eingeben.
        Gebucht wird auf <strong><?= h($ich['anzeigename']) ?></strong>.
      </div>
    </div>
  <?php endif; ?>

</main>

<script>
// Das Scanfeld behält den Fokus, damit der nächste Scan sofort ankommt –
// ein Handscanner "tippt" ins fokussierte Feld. Nach jeder Buchung und
// nach jedem Klick daneben zurück ins Feld.
(function () {
  const feld = document.getElementById('t-scan-feld');
  if (!feld) return;
  const fokus = () => { feld.focus(); };
  fokus();
  document.addEventListener('click', e => {
    // Nicht stören bei Knopf, Menge, Auswahl – oder dem Suchfeld, das
    // eine echte Tastatur braucht.
    if (e.target.closest('button, input[type=number], select, #t-suche-feld, .t-suche')) return;
    setTimeout(fokus, 50);
  });
  // Wer ins Suchfeld tippt, soll dort bleiben – Scanfeld gibt den Fokus ab.
  const suche = document.getElementById('t-suche-feld');
  if (suche) suche.addEventListener('focus', () => { feld.blur(); });

  // Plus/Minus an den Mengenfeldern.
  document.querySelectorAll('.t-step').forEach(b => {
    b.addEventListener('click', () => {
      const feld = b.parentElement.querySelector('.t-menge');
      const wert = Math.max(1, (parseInt(feld.value, 10) || 1) + parseInt(b.dataset.step, 10));
      feld.value = wert;
    });
  });

  // Eine Erfolgsquittung nach ein paar Sekunden ausblenden, damit das
  // Terminal von selbst wieder in den Ausgangszustand zurückkehrt.
  const q = document.querySelector('.t-quittung');
  if (q) setTimeout(() => { q.style.opacity = '0'; }, 6000);
})();
</script>

</body>
</html>
