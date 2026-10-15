<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Buchungsterminal für ein Tablet im Lager.
 *
 * Vier Bereiche, jeder mit einem Fingertipp erreichbar – auf der
 * Startseite als große Kacheln, sonst in der Leiste oben:
 *
 *   Entnahme   scannen oder suchen, Menge, Grund, entnehmen
 *   Einlagern  dasselbe in die andere Richtung, bei Chargen mit MHD
 *   Übersicht  Bestand nach Kategorie durchsehen
 *   Bedarf     was abgelaufen ist, was fehlt, was bald abläuft
 *
 * Alles ist auf Fingerbedienung ausgelegt: Bedienelemente ab 60 px,
 * Auswahl über große Schaltflächen statt Klapplisten, keine Hover-
 * Effekte. Nach einer Buchung geht es per Weiterleitung zurück in den
 * Bereich, sodass der nächste Artikel sofort gescannt werden kann und
 * ein Neuladen nichts doppelt bucht.
 *
 * Die Buchung nutzt dieselben Funktionen wie die übrige Anwendung
 * (bestand_rechnen, chargen_mitbuchen, code_deuten) – hier ist nur die
 * Oberfläche eine andere, nicht die Logik.
 *
 * USB-Handscanner: verhält sich wie eine Tastatur, deshalb genügt ein
 * Textfeld mit Dauerfokus. Keine Kamera.
 */

const T_BEREICHE = [
    'entnahme'   => 'Entnahme',
    'einlagern'  => 'Einlagern',
    'uebersicht' => 'Übersicht',
    'bedarf'     => 'Bedarf',
];

// Gründe am Terminal, der erste ist vorausgewählt. Eine Leihe braucht
// den Namen des Entleihers und läuft deshalb über die Vollansicht.
const T_GRUENDE_AB = ['einsatz', 'verschleiss', 'defekt', 'abgelaufen'];
const T_GRUENDE_ZU = ['beschaffung', 'ruecklauf', 'spende', 'korrektur'];

$ich = benutzer();
$m   = (string) ($_POST['m'] ?? $_GET['m'] ?? '');
if (!isset(T_BEREICHE[$m])) {
    $m = '';
}

/** Adresse innerhalb des Terminals; leere Werte fallen weg. */
function t_url(array $p = []): string
{
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'touch.php' . ($p ? '?' . http_build_query($p) : '');
}

/* ===================================================================
   Buchung
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    csrf_check();

    $artikelId = ctype_digit((string) ($_POST['artikel_id'] ?? '')) ? (int) $_POST['artikel_id'] : 0;
    $richtung  = (string) ($_POST['richtung'] ?? '');
    $menge     = (string) ($_POST['menge'] ?? '1');
    $einheitIn = (string) ($_POST['einheit'] ?? 'gebinde');
    $grund     = (string) ($_POST['grund'] ?? '');
    $gruende   = $richtung === 'zugang' ? T_GRUENDE_ZU : T_GRUENDE_AB;

    try {
        if (!in_array($richtung, ['zugang', 'abgang'], true)) {
            throw new RuntimeException('Unbekannte Buchungsrichtung.');
        }
        if (!ctype_digit($menge) || (int) $menge < 1) {
            throw new RuntimeException('Bitte eine Menge ab 1 eingeben.');
        }
        if (!in_array($grund, $gruende, true)) {
            throw new RuntimeException('Bitte einen Grund wählen.');
        }
        $menge = (int) $menge;

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT artikelname, menge, lose, ve_inhalt, ve_einheit, gebinde_einheit, mit_chargen
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

        // MHD-Chargen: Entnahme aus der gewählten Charge. Beim Einlagern
        // hat ein neu eingetragenes MHD Vorrang vor einer vorhandenen Charge.
        $mhd = mhd_aus_eingabe((string) ($_POST['mhd'] ?? ''));
        if ($richtung === 'zugang') {
            $mhd = mhd_aus_eingabe((string) ($_POST['mhd_neu'] ?? '')) ?? $mhd;
        }
        $mhd = chargen_mitbuchen($pdo, $art, $artikelId, stueckzahl($art, $menge, $einheit),
                                 $richtung === 'zugang', $mhd);

        if ($richtung === 'abgang') {
            $pdo->prepare(
                'INSERT INTO entnahme (artikel_id, menge, einheit, mhd, grund, entnommen_am, person, notiz, erfasst_von)
                 VALUES (?, ?, ?, ?, ?, CURDATE(), ?, "am Terminal gebucht", ?)'
            )->execute([$artikelId, $menge, $einheit, $mhd, $grund, $ich['anzeigename'], (int) $ich['benutzer_id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO zugang (artikel_id, menge, einheit, mhd, grund, datum, person, notiz, erfasst_von)
                 VALUES (?, ?, ?, ?, ?, CURDATE(), ?, "am Terminal gebucht", ?)'
            )->execute([$artikelId, $menge, $einheit, $mhd, $grund, $ich['anzeigename'], (int) $ich['benutzer_id']]);
        }
        $pdo->commit();

        $art['menge'] = $neueMenge;
        $art['lose']  = $neueLose;
        $art['gesamt_einzeln'] = $neueMenge * (int) ($art['ve_inhalt'] ?? 1) + $neueLose;

        $_SESSION['t_quittung'] = [
            'richtung' => $richtung,
            'menge'    => einheit_text($art, $einheit, $menge)
                        . ($mhd !== null ? ' (MHD ' . datum_de($mhd) . ')' : ''),
            'name'     => $art['artikelname'],
            'grund'    => $richtung === 'zugang' ? zugang_label($grund) : grund_label($grund),
            'stand'    => bestand_text($art),
        ];
        redirect(t_url(['m' => $m]));

    } catch (RuntimeException $e) {
        $fehler = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Terminal-Buchung: ' . $e->getMessage());
        $fehler = 'Die Buchung konnte nicht gespeichert werden.';
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Fehler: zurück zur Karte desselben Artikels, mit Meldung.
    $_SESSION['t_fehler'] = $fehler;
    redirect(t_url(['m' => $m, 'a' => $artikelId ?: null, 'g' => $grund]));
}

$quittung = $_SESSION['t_quittung'] ?? null;
$fehler   = $_SESSION['t_fehler'] ?? null;
unset($_SESSION['t_quittung'], $_SESSION['t_fehler']);

/* ===================================================================
   Was wird angezeigt?
   =================================================================== */
$eingabe = trim((string) ($_GET['c'] ?? $_GET['q'] ?? ''));
$code    = $eingabe !== '' ? code_deuten($eingabe) : ['art' => 'leer', 'wert' => ''];
$treffer = [];
$kiste   = null;
$vorGrund = (string) ($_GET['g'] ?? '');

// Kommt die Eingabe aus dem Suchfeld (q) statt vom Scanner (c), immer als
// Text suchen – auch wenn sie zufällig wie eine Kennung aussieht.
if (isset($_GET['q']) && $eingabe !== '') {
    $code = ['art' => 'suche', 'wert' => $eingabe];
}

if (isset($_GET['a']) && ctype_digit((string) $_GET['a'])) {
    // Direkt ein Artikel – aus einer Liste angetippt.
    $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE artikel_id = ?');
    $stmt->execute([(int) $_GET['a']]);
    $treffer = $stmt->fetchAll();
} elseif ($code['art'] === 'kiste') {
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
    $stmt = $pdo->prepare(
        "SELECT * FROM v_artikel
          WHERE CONCAT_WS(' ', inventar_nr, IFNULL(ean,''), artikelname,
                          IFNULL(kiste_nr,''), IFNULL(lagerort_text,''),
                          IFNULL(kategorie,'')) LIKE ?
          ORDER BY artikelname LIMIT 40"
    );
    $stmt->execute(['%' . $eingabe . '%']);
    $treffer = $stmt->fetchAll();
}

// Bei genau einem Treffer direkt die Karte; eine gescannte Kiste zeigt
// immer ihren Inhalt als Liste.
$karte = (count($treffer) === 1 && !$kiste) ? $treffer[0] : null;

// Gescannt ohne gewählten Bereich: die Karte mit beiden Richtungen.
if ($m === '' && ($karte || $treffer || $eingabe !== '')) {
    $m = 'uebersicht';
}

// Übersicht ohne Suche: Liste nach Kategorie.
$kategorien = [];
$katWahl    = (string) ($_GET['kat'] ?? '');
$liste      = [];
if ($m === 'uebersicht' && $eingabe === '' && !$treffer) {
    $kategorien = $pdo->query('SELECT kategorie_id, bezeichnung FROM kategorie ORDER BY bezeichnung')->fetchAll();
    if ($katWahl === 'ohne') {
        $liste = $pdo->query('SELECT * FROM v_artikel WHERE kategorie_id IS NULL ORDER BY artikelname')->fetchAll();
    } elseif (ctype_digit($katWahl)) {
        $stmt = $pdo->prepare('SELECT * FROM v_artikel WHERE kategorie_id = ? ORDER BY artikelname');
        $stmt->execute([(int) $katWahl]);
        $liste = $stmt->fetchAll();
    } else {
        $katWahl = '';
        $liste = $pdo->query('SELECT * FROM v_artikel ORDER BY artikelname')->fetchAll();
    }
}

// Bedarf: für den Bereich selbst und als Zähler auf Kachel und Leiste.
$bedarf       = bedarf_laden($pdo);
$bedarfAnzahl = count($bedarf['ueberfaellig']) + count($bedarf['unterdeckung']) + count($bedarf['bald']);

/* ===================================================================
   Bausteine
   =================================================================== */

/** Kopf einer Artikelkarte: Name, Ort, Bestand, Hinweise. */
function t_kopf(array $a): void
{
    $gebinde = (int) $a['hat_gebinde'] === 1;
    $tage    = $a['tage_bis_faellig'] === null ? null : (int) $a['tage_bis_faellig'];
    ?>
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
    <div class="t-flags">
      <?php if ((int) $a['unter_soll'] === 1): ?>
        <span class="t-flag t-flag--rot">unter Soll – fehlen <?= (int) $a['fehlmenge'] ?> <?= h($a['ve_einheit'] ?: 'Stück') ?></span>
      <?php endif; ?>
      <?php if ($tage !== null && $tage < 0): ?>
        <span class="t-flag t-flag--rot"><?= h(faellig_label($a['faellig_typ'])) ?> seit <?= abs($tage) ?> Tagen</span>
      <?php elseif ($tage !== null && $tage <= WARNUNG_TAGE): ?>
        <span class="t-flag t-flag--amber"><?= h(faellig_label($a['faellig_typ'])) ?> in <?= $tage ?> Tagen</span>
      <?php endif; ?>
      <?php if ($a['chargen_text']): ?>
        <span class="t-flag">MHD <?= h($a['chargen_text']) ?></span>
      <?php endif; ?>
    </div>
    <?php
}

/** Eine Gruppe großer Auswahlknöpfe (Radio), die erste Option vorgewählt. */
function t_chips(string $name, array $optionen, string $gewaehlt, bool $spalte = false): void
{
    if (!isset($optionen[$gewaehlt])) {
        $gewaehlt = (string) array_key_first($optionen);
    }
    ?>
    <div class="t-chips<?= $spalte ? ' t-chips--spalte' : '' ?>">
      <?php foreach ($optionen as $wert => $text): ?>
        <label class="t-chip">
          <input type="radio" name="<?= h($name) ?>" value="<?= h((string) $wert) ?>"
                 <?= (string) $wert === $gewaehlt ? 'checked' : '' ?>>
          <span><?= $text ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Die Buchungskarte für Entnahme oder Einlagern: Menge, Einheit, Charge,
 * Grund und ein breiter Knopf.
 */
function t_buchungskarte(array $a, string $m, string $grund): void
{
    global $pdo;
    $ab      = $m === 'entnahme';
    $gebinde = (int) $a['hat_gebinde'] === 1;
    $chargen = (int) $a['mit_chargen'] === 1 ? chargen_laden($pdo, (int) $a['artikel_id']) : null;
    $leer    = $ab && (int) $a['gesamt_einzeln'] === 0;

    $gruende = [];
    foreach ($ab ? T_GRUENDE_AB : T_GRUENDE_ZU as $g) {
        $gruende[$g] = h($ab ? grund_label($g) : zugang_label($g));
    }
    ?>
    <div class="t-artikel t-artikel--<?= $ab ? 'raus' : 'rein' ?>">
      <?php t_kopf($a); ?>

      <form method="post" action="touch.php" class="t-form">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="buchen">
        <input type="hidden" name="m" value="<?= h($m) ?>">
        <input type="hidden" name="artikel_id" value="<?= (int) $a['artikel_id'] ?>">
        <input type="hidden" name="richtung" value="<?= $ab ? 'abgang' : 'zugang' ?>">

        <div class="t-feldname">Menge</div>
        <div class="t-mengenzeile">
          <button type="button" class="t-step" data-step="-1" aria-label="eins weniger">−</button>
          <input type="number" name="menge" value="1" min="1" step="1" inputmode="numeric"
                 class="t-menge" aria-label="Menge">
          <button type="button" class="t-step" data-step="1" aria-label="eins mehr">+</button>
          <button type="button" class="t-step t-step--zehn" data-step="10" aria-label="zehn mehr">+10</button>
        </div>

        <?php if ($gebinde): ?>
          <?php t_chips('einheit', [
              'gebinde' => h(mehrzahl($a['gebinde_einheit'] ?: 'Gebinde')),
              'einzeln' => h($a['ve_einheit'] ?: 'Stück'),
          ], 'gebinde'); ?>
        <?php else: ?>
          <input type="hidden" name="einheit" value="einzeln">
        <?php endif; ?>

        <?php if ($chargen !== null && ($chargen || !$ab)): ?>
          <div class="t-feldname"><?= $ab ? 'Aus Charge' : 'MHD der Ware' ?></div>
          <?php
            $opt = [];
            foreach ($chargen as $c) {
                $tage = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($c['mhd']))->format('%r%a');
                $opt[$c['mhd']] = '<strong>MHD ' . h(datum_de($c['mhd'])) . '</strong>'
                    . '<em>' . h(einzeln_text($a, (int) $c['menge'])) . ($tage < 0 ? ' · abgelaufen' : '') . '</em>';
            }
            if (!$ab) {
                $opt[''] = '<strong>Neues MHD</strong><em>Datum unten eintragen</em>';
            }
            t_chips('mhd', $opt, '', true);
          ?>
          <?php if (!$ab): ?>
            <input type="date" name="mhd_neu" class="t-datum" aria-label="neues MHD"
                   <?= $chargen ? '' : 'required' ?>>
          <?php endif; ?>
        <?php endif; ?>

        <div class="t-feldname">Grund</div>
        <?php t_chips('grund', $gruende, $grund); ?>

        <button type="submit" class="t-btn t-btn--breit t-btn--<?= $ab ? 'raus' : 'rein' ?>"
                <?= $leer ? 'disabled' : '' ?>>
          <span class="t-btn-zeichen"><?= $ab ? '−' : '+' ?></span>
          <?= $leer ? 'Kein Bestand' : ($ab ? 'Entnehmen' : 'Einlagern') ?>
        </button>
      </form>
    </div>
    <?php
}

/** Artikelkarte in der Übersicht: Stand ansehen, dann Richtung wählen. */
function t_infokarte(array $a): void
{
    ?>
    <div class="t-artikel">
      <?php t_kopf($a); ?>
      <div class="t-knoepfe">
        <a class="t-btn t-btn--raus" href="<?= h(t_url(['m' => 'entnahme', 'a' => $a['artikel_id']])) ?>"
           <?= (int) $a['gesamt_einzeln'] === 0 ? 'aria-disabled="true"' : '' ?>>
          <span class="t-btn-zeichen">−</span> Entnehmen
        </a>
        <a class="t-btn t-btn--rein" href="<?= h(t_url(['m' => 'einlagern', 'a' => $a['artikel_id']])) ?>">
          <span class="t-btn-zeichen">+</span> Einlagern
        </a>
      </div>
    </div>
    <?php
}

/** Eine antippbare Zeile in Listen; führt zur Karte im Bereich $m. */
function t_zeile(array $a, string $m, string $info = '', string $stufe = '', string $grund = ''): void
{
    $gebinde = (int) $a['hat_gebinde'] === 1;
    ?>
    <a class="t-zeile<?= $stufe ? ' t-zeile--' . h($stufe) : '' ?>"
       href="<?= h(t_url(['m' => $m, 'a' => $a['artikel_id'], 'g' => $grund])) ?>">
      <span class="t-zeile-text">
        <span class="t-zeile-name"><?= h($a['artikelname']) ?></span>
        <span class="t-zeile-info"><?= h($info !== '' ? $info : ($a['lagerort_text'] ?? 'ohne Lagerort')) ?></span>
      </span>
      <span class="t-zeile-zahl">
        <?= (int) ($gebinde ? $a['menge'] : $a['gesamt_einzeln']) ?>
        <small><?= h($gebinde ? mehrzahl($a['gebinde_einheit'] ?: 'Gebinde') : ($a['ve_einheit'] ?: 'Stück')) ?></small>
      </span>
    </a>
    <?php
}

/** Scanfeld und Textsuche, beide bleiben im aktuellen Bereich. */
function t_eingabe(string $m, string $eingabe, bool $istSuche, string $hinweis): void
{
    ?>
    <div class="t-eingabe">
      <form method="get" action="touch.php" class="t-scan">
        <?php if ($m !== ''): ?><input type="hidden" name="m" value="<?= h($m) ?>"><?php endif; ?>
        <input type="text" name="c" id="t-scan-feld" value="" autocomplete="off"
               inputmode="none" placeholder="<?= h($hinweis) ?>">
        <button type="submit" class="t-scan-btn">Los</button>
      </form>
      <form method="get" action="touch.php" class="t-suche">
        <?php if ($m !== ''): ?><input type="hidden" name="m" value="<?= h($m) ?>"><?php endif; ?>
        <input type="text" name="q" id="t-suche-feld" value="<?= $istSuche ? h($eingabe) : '' ?>"
               autocomplete="off" placeholder="oder Namen eingeben …">
        <button type="submit" class="t-suche-btn">Suchen</button>
      </form>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="de"<?= darstellung_attribute() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Terminal – <?= h(ORGANISATION) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<?= darstellung_kopf() ?>
<link rel="stylesheet" href="assets/touch.css">
</head>
<body class="t-body" data-bereich="<?= h($m) ?>">

<header class="t-topbar">
  <a class="t-marke" href="touch.php"><?= h(ORGANISATION) ?> · Terminal</a>
  <div class="t-topbar-rechts">
    <span class="t-wer"><?= h($ich['anzeigename']) ?></span>
    <?php hell_dunkel_schalter('t-hell-dunkel'); ?>
    <a class="t-verlassen" href="index.php">Vollansicht</a>
  </div>
</header>

<?php if ($m !== ''): ?>
  <nav class="t-nav" aria-label="Bereiche">
    <a class="t-nav-link t-nav-link--start" href="touch.php" aria-label="Start">⌂</a>
    <?php foreach (T_BEREICHE as $key => $label): ?>
      <a class="t-nav-link t-nav-link--<?= h($key) ?><?= $m === $key ? ' is-aktiv' : '' ?>"
         href="<?= h(t_url(['m' => $key])) ?>"<?= $m === $key ? ' aria-current="page"' : '' ?>>
        <?= h($label) ?>
        <?php if ($key === 'bedarf' && $bedarfAnzahl > 0): ?><span class="t-zaehler"><?= $bedarfAnzahl ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>

<main class="t-main">

  <?php if ($quittung): ?>
    <div class="t-quittung t-quittung--<?= $quittung['richtung'] === 'abgang' ? 'raus' : 'rein' ?>">
      <div class="t-quittung-zeichen"><?= $quittung['richtung'] === 'abgang' ? '−' : '+' ?></div>
      <div class="t-quittung-text">
        <strong><?= h($quittung['menge']) ?></strong> <?= h($quittung['name']) ?>
        <?= $quittung['richtung'] === 'abgang' ? 'entnommen' : 'eingelagert' ?> (<?= h($quittung['grund']) ?>)
      </div>
      <div class="t-quittung-stand">Neuer Bestand: <?= h($quittung['stand']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($fehler): ?>
    <div class="t-fehler"><?= h($fehler) ?></div>
  <?php endif; ?>

  <?php /* ---------------- Start: vier Kacheln ---------------- */ ?>
  <?php if ($m === ''): ?>
    <div class="t-kacheln">
      <a class="t-kachel t-kachel--entnahme" href="<?= h(t_url(['m' => 'entnahme'])) ?>">
        <span class="t-kachel-zeichen">−</span>
        <span class="t-kachel-titel">Entnahme</span>
        <span class="t-kachel-sub">Material ausbuchen</span>
      </a>
      <a class="t-kachel t-kachel--einlagern" href="<?= h(t_url(['m' => 'einlagern'])) ?>">
        <span class="t-kachel-zeichen">+</span>
        <span class="t-kachel-titel">Einlagern</span>
        <span class="t-kachel-sub">Nachschub und Rücklauf</span>
      </a>
      <a class="t-kachel t-kachel--uebersicht" href="<?= h(t_url(['m' => 'uebersicht'])) ?>">
        <span class="t-kachel-zeichen">≡</span>
        <span class="t-kachel-titel">Übersicht</span>
        <span class="t-kachel-sub">Was ist wo, wie viel</span>
      </a>
      <a class="t-kachel t-kachel--bedarf" href="<?= h(t_url(['m' => 'bedarf'])) ?>">
        <span class="t-kachel-zeichen">!</span>
        <span class="t-kachel-titel">Bedarf</span>
        <span class="t-kachel-sub">
          <?= $bedarfAnzahl > 0 ? $bedarfAnzahl . ' Artikel brauchen Aufmerksamkeit' : 'Alles im grünen Bereich' ?>
        </span>
        <?php if ($bedarfAnzahl > 0): ?><span class="t-zaehler t-zaehler--gross"><?= $bedarfAnzahl ?></span><?php endif; ?>
      </a>
    </div>
    <?php t_eingabe('', '', false, 'Barcode scannen …'); ?>
    <p class="t-hinweis t-hinweis--mitte">
      Ein Scan hier zeigt den Artikel. Gebucht wird auf <strong><?= h($ich['anzeigename']) ?></strong>.
    </p>

  <?php /* ---------------- Bedarf ---------------- */ ?>
  <?php elseif ($m === 'bedarf'): ?>
    <?php if ($bedarfAnzahl === 0): ?>
      <div class="t-start">
        <div class="t-start-zeichen" aria-hidden="true">✓</div>
        <div class="t-start-text">Nichts zu tun</div>
        <div class="t-start-sub">Nichts abgelaufen, nichts unter Sollbestand, nichts läuft in den nächsten <?= (int) WARNUNG_TAGE ?> Tagen ab.</div>
      </div>
    <?php endif; ?>

    <?php if ($bedarf['ueberfaellig']): ?>
      <h2 class="t-abschnitt">Abgelaufen oder überfällig <span><?= count($bedarf['ueberfaellig']) ?></span></h2>
      <?php foreach ($bedarf['ueberfaellig'] as $a):
          $tage = abs((int) $a['tage_bis_faellig']);
          $istAblauf = $a['faellig_typ'] === 'ablauf';
          // Abgelaufenes führt direkt zur Entnahme mit dem passenden Grund;
          // eine verpasste Prüfung zur Übersicht.
          t_zeile($a, $istAblauf ? 'entnahme' : 'uebersicht',
                  faellig_label($a['faellig_typ']) . ' seit ' . $tage . ' Tagen (' . datum_de($a['faellig_am']) . ')',
                  'rot', $istAblauf ? 'abgelaufen' : '');
      endforeach; ?>
    <?php endif; ?>

    <?php if ($bedarf['unterdeckung']): ?>
      <h2 class="t-abschnitt">Unter Sollbestand <span><?= count($bedarf['unterdeckung']) ?></span></h2>
      <?php foreach ($bedarf['unterdeckung'] as $a):
          t_zeile($a, 'einlagern',
                  'Fehlen ' . (int) $a['fehlmenge'] . ' ' . ($a['ve_einheit'] ?: 'Stück') . ' · Soll ' . (int) $a['soll_bestand'],
                  'rot', 'beschaffung');
      endforeach; ?>
    <?php endif; ?>

    <?php if ($bedarf['bald']): ?>
      <h2 class="t-abschnitt">Läuft bald ab <span><?= count($bedarf['bald']) ?></span></h2>
      <?php foreach ($bedarf['bald'] as $a):
          t_zeile($a, 'uebersicht',
                  faellig_label($a['faellig_typ']) . ' in ' . (int) $a['tage_bis_faellig'] . ' Tagen (' . datum_de($a['faellig_am']) . ')',
                  'amber');
      endforeach; ?>
    <?php endif; ?>

  <?php /* ---------------- Entnahme, Einlagern, Übersicht ---------------- */ ?>
  <?php else: ?>
    <?php t_eingabe($m, $eingabe, $code['art'] === 'suche', [
        'entnahme'   => 'Zum Entnehmen scannen …',
        'einlagern'  => 'Zum Einlagern scannen …',
        'uebersicht' => 'Barcode scannen …',
    ][$m]); ?>

    <?php if ($karte): ?>
      <?php if ($m === 'uebersicht') { t_infokarte($karte); } else { t_buchungskarte($karte, $m, $vorGrund); } ?>

    <?php elseif ($kiste): ?>
      <div class="t-kistenkopf">
        <span class="t-kiste-nr"><?= h($kiste['kiste_nr']) ?></span>
        <span class="t-kiste-ort"><?= h($kiste['lagerort_text'] ?? '') ?></span>
        <span class="t-kiste-zahl"><?= count($treffer) ?> Positionen</span>
      </div>
      <?php if (!$treffer): ?>
        <p class="t-leer">Diese Kiste ist leer.</p>
      <?php endif; ?>
      <?php foreach ($treffer as $a) { t_zeile($a, $m); } ?>

    <?php elseif ($treffer): ?>
      <p class="t-hinweis"><?= count($treffer) ?> Artikel<?= $code['art'] === 'suche' ? ' zu „' . h($eingabe) . '“' : '' ?> – bitte antippen:</p>
      <?php foreach ($treffer as $a) { t_zeile($a, $m); } ?>

    <?php elseif ($eingabe !== ''): ?>
      <div class="t-nichts">
        <div class="t-nichts-zeichen" aria-hidden="true">?</div>
        <div>Kein Artikel zu <strong><?= h($eingabe) ?></strong> gefunden.</div>
        <div class="t-nichts-sub">Nochmal scannen oder oben nach dem Namen suchen.</div>
      </div>

    <?php elseif ($m === 'uebersicht'): ?>
      <div class="t-chips t-chips--filter">
        <a class="t-chip-link<?= $katWahl === '' ? ' is-aktiv' : '' ?>" href="<?= h(t_url(['m' => 'uebersicht'])) ?>">Alle</a>
        <?php foreach ($kategorien as $k): ?>
          <a class="t-chip-link<?= $katWahl === (string) $k['kategorie_id'] ? ' is-aktiv' : '' ?>"
             href="<?= h(t_url(['m' => 'uebersicht', 'kat' => $k['kategorie_id']])) ?>"><?= h($k['bezeichnung']) ?></a>
        <?php endforeach; ?>
        <a class="t-chip-link<?= $katWahl === 'ohne' ? ' is-aktiv' : '' ?>" href="<?= h(t_url(['m' => 'uebersicht', 'kat' => 'ohne'])) ?>">ohne Kategorie</a>
      </div>
      <p class="t-hinweis"><?= count($liste) ?> Artikel</p>
      <?php foreach ($liste as $a):
          $tage  = $a['tage_bis_faellig'] === null ? null : (int) $a['tage_bis_faellig'];
          $stufe = (int) $a['unter_soll'] === 1 || ($tage !== null && $tage < 0) ? 'rot'
                 : ($tage !== null && $tage <= WARNUNG_TAGE ? 'amber' : '');
          t_zeile($a, 'uebersicht', '', $stufe);
      endforeach; ?>

    <?php else: ?>
      <div class="t-start">
        <div class="t-start-zeichen" aria-hidden="true"><?= $m === 'entnahme' ? '−' : '+' ?></div>
        <div class="t-start-text"><?= $m === 'entnahme' ? 'Was wird entnommen?' : 'Was wird eingelagert?' ?></div>
        <div class="t-start-sub">Artikel, Kiste oder Herstellercode scannen – oder oben den Namen eingeben.</div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</main>

<script>
(function () {
  // Das Scanfeld behält den Fokus, damit der nächste Scan sofort ankommt –
  // ein Handscanner "tippt" ins fokussierte Feld. preventScroll, damit
  // ein Tipp weiter unten in einer Liste die Seite nicht nach oben reißt.
  const feld = document.getElementById('t-scan-feld');
  const fokus = () => { if (feld) feld.focus({ preventScroll: true }); };
  fokus();
  document.addEventListener('click', e => {
    // Nicht stören bei Eingaben, die selbst den Fokus brauchen.
    if (e.target.closest('input[type=number], input[type=date], #t-suche-feld, .t-suche')) return;
    setTimeout(fokus, 50);
  });
  const suche = document.getElementById('t-suche-feld');
  if (suche && feld) suche.addEventListener('focus', () => { feld.blur(); });

  // Plus/Minus an der Menge.
  document.querySelectorAll('.t-step').forEach(b => {
    b.addEventListener('click', () => {
      const m = b.closest('.t-mengenzeile').querySelector('.t-menge');
      m.value = Math.max(1, (parseInt(m.value, 10) || 1) + parseInt(b.dataset.step, 10));
    });
  });

  // Wer ein neues MHD einträgt, meint auch "neues MHD".
  document.querySelectorAll('.t-datum').forEach(d => {
    d.addEventListener('input', () => {
      const neu = d.form.querySelector('input[name=mhd][value=""]');
      if (neu) neu.checked = true;
    });
  });

  // Quittung nach ein paar Sekunden ausblenden.
  const q = document.querySelector('.t-quittung');
  if (q) setTimeout(() => { q.style.opacity = '0'; }, 6000);

  // Zwei Minuten ohne Bedienung: zurück zur Startseite, damit die nächste
  // Person nicht in einer halb ausgefüllten Buchung landet.
  if (document.body.dataset.bereich !== '') {
    let uhr;
    const neu = () => { clearTimeout(uhr); uhr = setTimeout(() => { location.href = 'touch.php'; }, 120000); };
    ['pointerdown', 'keydown', 'scroll'].forEach(ev => document.addEventListener(ev, neu, { passive: true }));
    neu();
  }
})();
</script>

</body>
</html>
