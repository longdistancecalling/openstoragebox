<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Lagerorte verwalten: Lager, Regale und Fächer.
 *
 * Die drei Ebenen werden auf einer Seite gepflegt, weil sie
 * zusammengehören und man beim Aufbau eines Lagers ohnehin von oben
 * nach unten arbeitet. Ausgewählt wird über die Adresszeile
 * (?lager=…&regal=…), damit sich ein Stand verlinken und nach dem
 * Anlegen gezielt wieder anzeigen lässt.
 */

$fehler = [];

$lagerId = ctype_digit((string) ($_GET['lager'] ?? '')) ? (int) $_GET['lager'] : 0;
$regalId = ctype_digit((string) ($_GET['regal'] ?? '')) ? (int) $_GET['regal'] : 0;

/* ===================================================================
   Aktionen
   =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string) ($_POST['aktion'] ?? '');
    $name   = trim((string) ($_POST['name'] ?? ''));

    try {
        // --- Anlegen ---------------------------------------------------
        if ($aktion === 'lager_neu') {
            if ($name === '') { throw new RuntimeException('Bitte einen Namen für das Lager eingeben.'); }
            $pdo->prepare('INSERT INTO lager (name) VALUES (?)')->execute([$name]);
            flash('Lager „' . $name . '" angelegt.');
            redirect('orte.php?lager=' . (int) $pdo->lastInsertId());

        } elseif ($aktion === 'regal_neu') {
            $lid = (int) ($_POST['lager_id'] ?? 0);
            if ($name === '') { throw new RuntimeException('Bitte einen Namen für das Regal eingeben.'); }
            $pdo->prepare('INSERT INTO regal (lager_id, name) VALUES (?, ?)')->execute([$lid, $name]);
            flash('Regal „' . $name . '" angelegt.');
            redirect('orte.php?lager=' . $lid . '&regal=' . (int) $pdo->lastInsertId());

        } elseif ($aktion === 'fach_neu') {
            $rid = (int) ($_POST['regal_id'] ?? 0);
            $lid = (int) ($_POST['lager_id'] ?? 0);
            if ($name === '') { throw new RuntimeException('Bitte einen Namen für das Fach eingeben.'); }
            $pdo->prepare('INSERT INTO fach (regal_id, name) VALUES (?, ?)')->execute([$rid, $name]);
            flash('Fach „' . $name . '" angelegt.');
            redirect('orte.php?lager=' . $lid . '&regal=' . $rid);

        // --- Umbenennen ------------------------------------------------
        } elseif ($aktion === 'umbenennen') {
            $ebene = (string) ($_POST['ebene'] ?? '');
            $id    = (int) ($_POST['id'] ?? 0);
            if ($name === '') { throw new RuntimeException('Der Name darf nicht leer sein.'); }
            $tabelle = ['lager' => 'lager', 'regal' => 'regal', 'fach' => 'fach'][$ebene] ?? null;
            $spalte  = ['lager' => 'lager_id', 'regal' => 'regal_id', 'fach' => 'fach_id'][$ebene] ?? null;
            if (!$tabelle) { throw new RuntimeException('Unbekannte Ebene.'); }
            $pdo->prepare("UPDATE $tabelle SET name = ? WHERE $spalte = ?")->execute([$name, $id]);
            flash('Umbenannt.');
            redirect('orte.php?lager=' . $lagerId . '&regal=' . $regalId);

        // --- Löschen (nur Admin, nur wenn leer) ------------------------
        } elseif ($aktion === 'loeschen') {
            erfordere('admin');
            $ebene = (string) ($_POST['ebene'] ?? '');
            $id    = (int) ($_POST['id'] ?? 0);

            // Belegung prüfen – ein Ort mit Inhalt wird nicht gelöscht,
            // sonst verlören Kisten oder Artikel unbemerkt ihren Platz.
            $belegt = ort_belegt($pdo, $ebene, $id);
            if ($belegt > 0) {
                throw new RuntimeException(
                    'Hier liegen noch ' . $belegt . ' Kisten oder Artikel. '
                    . 'Erst umräumen, dann löschen.'
                );
            }
            $tabelle = ['lager' => 'lager', 'regal' => 'regal', 'fach' => 'fach'][$ebene] ?? null;
            $spalte  = ['lager' => 'lager_id', 'regal' => 'regal_id', 'fach' => 'fach_id'][$ebene] ?? null;
            if (!$tabelle) { throw new RuntimeException('Unbekannte Ebene.'); }
            $pdo->prepare("DELETE FROM $tabelle WHERE $spalte = ?")->execute([$id]);
            flash('Gelöscht.');
            // Eine Ebene nach oben springen
            redirect('orte.php' . ($ebene === 'fach' ? '?lager=' . $lagerId . '&regal=' . $regalId
                                 : ($ebene === 'regal' ? '?lager=' . $lagerId : '')));
        }
    } catch (RuntimeException $e) {
        $fehler[] = $e->getMessage();
    } catch (PDOException $e) {
        // 1062 = Name existiert in dieser Ebene schon
        $fehler[] = (int) ($e->errorInfo[1] ?? 0) === 1062
            ? 'Diesen Namen gibt es hier bereits.'
            : 'Der Vorgang konnte nicht gespeichert werden.';
    }
}

/**
 * Zählt, wie viele Kisten und Artikel an einem Ort (samt Unterebenen)
 * liegen. Grundlage für die Löschsperre.
 */
function ort_belegt(PDO $pdo, string $ebene, int $id): int
{
    if ($ebene === 'fach') {
        $k = $pdo->prepare('SELECT COUNT(*) FROM kiste WHERE fach_id = ?');
        $k->execute([$id]);
        $a = $pdo->prepare('SELECT COUNT(*) FROM artikel WHERE fach_id = ?');
        $a->execute([$id]);
        return (int) $k->fetchColumn() + (int) $a->fetchColumn();
    }
    if ($ebene === 'regal') {
        $q = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM kiste k JOIN fach f ON f.fach_id = k.fach_id WHERE f.regal_id = ?)
                  + (SELECT COUNT(*) FROM artikel a JOIN fach f ON f.fach_id = a.fach_id WHERE f.regal_id = ?)'
        );
        $q->execute([$id, $id]);
        return (int) $q->fetchColumn();
    }
    // lager
    $q = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM kiste k JOIN fach f ON f.fach_id=k.fach_id JOIN regal r ON r.regal_id=f.regal_id WHERE r.lager_id = ?)
              + (SELECT COUNT(*) FROM artikel a JOIN fach f ON f.fach_id=a.fach_id JOIN regal r ON r.regal_id=f.regal_id WHERE r.lager_id = ?)'
    );
    $q->execute([$id, $id]);
    return (int) $q->fetchColumn();
}

/* ===================================================================
   Daten für die Anzeige
   =================================================================== */
$lagerListe = $pdo->query('SELECT * FROM lager ORDER BY sortierung, name')->fetchAll();

$regalListe = [];
$aktLager = null;
if ($lagerId) {
    foreach ($lagerListe as $l) { if ((int) $l['lager_id'] === $lagerId) { $aktLager = $l; } }
    if ($aktLager) {
        $stmt = $pdo->prepare('SELECT * FROM regal WHERE lager_id = ? ORDER BY sortierung, LENGTH(name), name');
        $stmt->execute([$lagerId]);
        $regalListe = $stmt->fetchAll();
    }
}

$fachListe = [];
$aktRegal = null;
if ($regalId) {
    foreach ($regalListe as $r) { if ((int) $r['regal_id'] === $regalId) { $aktRegal = $r; } }
    if ($aktRegal) {
        // Fächer mit Belegungszahl, damit man sieht, wo etwas liegt.
        $stmt = $pdo->prepare(
            'SELECT f.*,
                    (SELECT COUNT(*) FROM kiste k WHERE k.fach_id = f.fach_id) AS kisten,
                    (SELECT COUNT(*) FROM artikel a WHERE a.fach_id = f.fach_id) AS artikel
             FROM fach f WHERE f.regal_id = ? ORDER BY f.sortierung, LENGTH(f.name), f.name'
        );
        $stmt->execute([$regalId]);
        $fachListe = $stmt->fetchAll();
    }
}

$titel = 'Lagerorte';
$aktiv = 'orte';
require __DIR__ . '/inc/header.php';
?>

<div class="kopf">
  <h1>Lagerorte</h1>
  <span class="anzahl"><?= count($lagerListe) ?> Lager</span>
</div>

<?php if ($fehler): ?>
  <ul class="fehlerliste"><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<p class="hinweis" style="margin-bottom:18px">
  Hier wird die Struktur des Lagers aufgebaut: <strong>Lager → Regal → Fach</strong>.
  In ein Fach kommen dann Kisten oder direkt Artikel. Eine Spalte auswählen,
  um die nächste Ebene zu sehen.
</p>

<div class="orte-spalten">

  <?php /* ---------- Spalte 1: Lager ---------- */ ?>
  <section class="orte-spalte">
    <h2>Lager</h2>
    <div class="orte-liste">
      <?php foreach ($lagerListe as $l): ?>
        <a class="orte-eintrag<?= (int) $l['lager_id'] === $lagerId ? ' is-active' : '' ?>"
           href="orte.php?lager=<?= (int) $l['lager_id'] ?>">
          <span class="orte-name"><?= h($l['name']) ?></span>
          <span class="orte-pfeil">›</span>
        </a>
      <?php endforeach; ?>
      <?php if (!$lagerListe): ?><p class="orte-leer">Noch kein Lager.</p><?php endif; ?>
    </div>
    <form method="post" action="orte.php<?= $lagerId ? '?lager=' . $lagerId : '' ?>" class="orte-neu">
      <?= csrf_field() ?>
      <input type="hidden" name="aktion" value="lager_neu">
      <input type="text" name="name" maxlength="60" required placeholder="Neues Lager (z. B. Keller)">
      <button class="btn btn--primaer" type="submit">+</button>
    </form>
  </section>

  <?php /* ---------- Spalte 2: Regale ---------- */ ?>
  <section class="orte-spalte<?= $aktLager ? '' : ' orte-spalte--leer' ?>">
    <h2><?= $aktLager ? 'Regale in ' . h($aktLager['name']) : 'Regale' ?></h2>
    <?php if ($aktLager): ?>
      <div class="orte-liste">
        <?php foreach ($regalListe as $r): ?>
          <a class="orte-eintrag<?= (int) $r['regal_id'] === $regalId ? ' is-active' : '' ?>"
             href="orte.php?lager=<?= $lagerId ?>&amp;regal=<?= (int) $r['regal_id'] ?>">
            <span class="orte-name"><?= h($r['name']) ?></span>
            <span class="orte-pfeil">›</span>
          </a>
        <?php endforeach; ?>
        <?php if (!$regalListe): ?><p class="orte-leer">Noch kein Regal.</p><?php endif; ?>
      </div>
      <form method="post" action="orte.php?lager=<?= $lagerId ?>" class="orte-neu">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="regal_neu">
        <input type="hidden" name="lager_id" value="<?= $lagerId ?>">
        <input type="text" name="name" maxlength="60" required placeholder="Neues Regal (z. B. R1)">
        <button class="btn btn--primaer" type="submit">+</button>
      </form>
      <?php if (darf('admin') && !$regalListe): ?>
        <form method="post" action="orte.php" class="orte-loeschen"
              onsubmit="return confirm('Lager „<?= h($aktLager['name']) ?>“ löschen?');">
          <?= csrf_field() ?>
          <input type="hidden" name="aktion" value="loeschen">
          <input type="hidden" name="ebene" value="lager">
          <input type="hidden" name="id" value="<?= $lagerId ?>">
          <button class="btn btn--klein" type="submit">Lager löschen</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="orte-hinweis">Links ein Lager wählen.</p>
    <?php endif; ?>
  </section>

  <?php /* ---------- Spalte 3: Fächer ---------- */ ?>
  <section class="orte-spalte<?= $aktRegal ? '' : ' orte-spalte--leer' ?>">
    <h2><?= $aktRegal ? 'Fächer in ' . h($aktRegal['name']) : 'Fächer' ?></h2>
    <?php if ($aktRegal): ?>
      <div class="orte-liste">
        <?php foreach ($fachListe as $f):
            $belegt = (int) $f['kisten'] + (int) $f['artikel'];
        ?>
          <div class="orte-eintrag orte-eintrag--fach">
            <span class="orte-name"><?= h($f['name']) ?></span>
            <?php if ($belegt > 0): ?>
              <span class="orte-belegt">
                <?= (int) $f['kisten'] ?> Kisten<?php if ((int) $f['artikel'] > 0): ?>, <?= (int) $f['artikel'] ?> Artikel<?php endif; ?>
              </span>
            <?php else: ?>
              <span class="orte-belegt orte-belegt--frei">leer</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$fachListe): ?><p class="orte-leer">Noch kein Fach.</p><?php endif; ?>
      </div>
      <form method="post" action="orte.php?lager=<?= $lagerId ?>&amp;regal=<?= $regalId ?>" class="orte-neu">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="fach_neu">
        <input type="hidden" name="lager_id" value="<?= $lagerId ?>">
        <input type="hidden" name="regal_id" value="<?= $regalId ?>">
        <input type="text" name="name" maxlength="60" required placeholder="Neues Fach (z. B. F3)">
        <button class="btn btn--primaer" type="submit">+</button>
      </form>
    <?php else: ?>
      <p class="orte-hinweis"><?= $aktLager ? 'Ein Regal wählen.' : 'Erst Lager und Regal wählen.' ?></p>
    <?php endif; ?>
  </section>

</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
