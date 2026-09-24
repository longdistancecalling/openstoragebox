<?php
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
erfordere('bearbeiter');

/*
 * Lagerorte verwalten: Lager, Regale und Fächer.
 *
 * Die drei Ebenen stehen auf einer Seite nebeneinander, weil man beim
 * Aufbau ohnehin von oben nach unten arbeitet. Ausgewählt wird über die
 * Adresszeile (?lager=…&regal=…), damit sich ein Stand verlinken lässt.
 *
 * Jede Zeile trägt zwei Aktionen: Umbenennen (Bearbeiter) und Löschen
 * (Admin). Umbenannt wird direkt in der Zeile – dazu schaltet ein Link
 * die Zeile über ?edit=ebene:id in ein Eingabefeld. Das kommt ohne
 * JavaScript aus und passt zum PRG-Muster der übrigen Seiten.
 */

$fehler = [];

$lagerId = ctype_digit((string) ($_GET['lager'] ?? '')) ? (int) $_GET['lager'] : 0;
$regalId = ctype_digit((string) ($_GET['regal'] ?? '')) ? (int) $_GET['regal'] : 0;

// Welche Zeile ist gerade im Umbenennen-Modus?
$editEbene = ''; $editId = 0;
if (preg_match('/^(lager|regal|fach):(\d+)$/', (string) ($_GET['edit'] ?? ''), $m)) {
    $editEbene = $m[1];
    $editId    = (int) $m[2];
}

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
            redirect(orte_sel_url($lagerId, $regalId));

        // --- Löschen (nur Admin, nur wenn nichts eingelagert) ----------
        } elseif ($aktion === 'loeschen') {
            erfordere('admin');
            $ebene = (string) ($_POST['ebene'] ?? '');
            $id    = (int) ($_POST['id'] ?? 0);

            // Eingelagertes zählen – ein Ort mit Kisten oder Artikeln
            // wird nie gelöscht, sonst verlöre Material unbemerkt seinen
            // Platz. Leere Regale/Fächer darunter dürfen mitgehen (der
            // Fremdschlüssel räumt sie per CASCADE mit weg).
            $belegt = ort_belegt($pdo, $ebene, $id);
            if ($belegt > 0) {
                throw new RuntimeException(
                    'Hier sind noch ' . $belegt . ' Kisten oder Artikel eingelagert. '
                    . 'Erst umräumen, dann löschen.'
                );
            }
            $tabelle = ['lager' => 'lager', 'regal' => 'regal', 'fach' => 'fach'][$ebene] ?? null;
            $spalte  = ['lager' => 'lager_id', 'regal' => 'regal_id', 'fach' => 'fach_id'][$ebene] ?? null;
            if (!$tabelle) { throw new RuntimeException('Unbekannte Ebene.'); }
            $pdo->prepare("DELETE FROM $tabelle WHERE $spalte = ?")->execute([$id]);
            flash('Gelöscht.');
            // Auf die jeweils übergeordnete Ebene springen – der gelöschte
            // Ort taucht dort nicht mehr auf.
            if ($ebene === 'fach') {
                redirect(orte_sel_url($lagerId, $regalId));
            } elseif ($ebene === 'regal') {
                redirect(orte_sel_url($lagerId, 0));
            } else {
                redirect('orte.php');
            }
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
 * eingelagert sind. Grundlage für die Löschsperre.
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

/** URL zurück zur aktuellen Auswahl (Umbenennen abbrechen, nach dem Speichern). */
function orte_sel_url(int $lagerId, int $regalId): string
{
    $p = array_filter(['lager' => $lagerId, 'regal' => $regalId]);
    return 'orte.php' . ($p ? '?' . http_build_query($p) : '');
}

/** URL, die die Auswahl erhält und eine Zeile in den Umbenennen-Modus schaltet. */
function orte_edit_url(string $ebene, int $id, int $lagerId, int $regalId): string
{
    $p = array_filter(['lager' => $lagerId, 'regal' => $regalId]);
    $p['edit'] = $ebene . ':' . $id;
    return 'orte.php?' . http_build_query($p);
}

/* ===================================================================
   Daten für die Anzeige
   =================================================================== */

// Lager mit Anzahl Regale und eingelagerter Menge darunter.
$lagerListe = $pdo->query(
    'SELECT l.lager_id, l.name, l.sortierung,
            (SELECT COUNT(*) FROM regal r WHERE r.lager_id = l.lager_id) AS regale,
            (SELECT COUNT(*) FROM kiste k JOIN fach f ON f.fach_id=k.fach_id JOIN regal r ON r.regal_id=f.regal_id WHERE r.lager_id=l.lager_id)
          + (SELECT COUNT(*) FROM artikel a JOIN fach f ON f.fach_id=a.fach_id JOIN regal r ON r.regal_id=f.regal_id WHERE r.lager_id=l.lager_id) AS belegt
     FROM lager l ORDER BY l.sortierung, l.name'
)->fetchAll();

$regalListe = [];
$aktLager = null;
if ($lagerId) {
    foreach ($lagerListe as $l) { if ((int) $l['lager_id'] === $lagerId) { $aktLager = $l; } }
    if ($aktLager) {
        $stmt = $pdo->prepare(
            'SELECT r.regal_id, r.name, r.sortierung,
                    (SELECT COUNT(*) FROM fach f WHERE f.regal_id = r.regal_id) AS faecher,
                    (SELECT COUNT(*) FROM kiste k JOIN fach f ON f.fach_id=k.fach_id WHERE f.regal_id=r.regal_id)
                  + (SELECT COUNT(*) FROM artikel a JOIN fach f ON f.fach_id=a.fach_id WHERE f.regal_id=r.regal_id) AS belegt
             FROM regal r WHERE r.lager_id = ? ORDER BY r.sortierung, LENGTH(r.name), r.name'
        );
        $stmt->execute([$lagerId]);
        $regalListe = $stmt->fetchAll();
    }
}

$fachListe = [];
$aktRegal = null;
if ($regalId) {
    foreach ($regalListe as $r) { if ((int) $r['regal_id'] === $regalId) { $aktRegal = $r; } }
    if ($aktRegal) {
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

$darfLoeschen = darf('admin');

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
  um die nächste Ebene zu sehen. Umbenennen darf jeder Bearbeiter, löschen nur
  ein Admin – und nur, solange nichts eingelagert ist.
</p>

<div class="orte-spalten">

  <?php /* ---------- Spalte 1: Lager ---------- */ ?>
  <section class="orte-spalte">
    <h2>Lager</h2>
    <div class="orte-liste">
      <?php foreach ($lagerListe as $l):
          $id = (int) $l['lager_id'];
          $imEdit = $editEbene === 'lager' && $editId === $id;
          $belegt = (int) $l['belegt'];
          $regale = (int) $l['regale'];
          $confirm = $regale > 0
              ? 'Lager „' . $l['name'] . '" samt ' . ($regale === 1 ? '1 Regal' : $regale . ' Regalen') . ' und allen Fächern löschen? Es ist nichts eingelagert.'
              : 'Lager „' . $l['name'] . '" löschen?';
      ?>
        <?php if ($imEdit): ?>
          <form method="post" action="<?= h(orte_sel_url($lagerId, $regalId)) ?>" class="orte-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="umbenennen">
            <input type="hidden" name="ebene" value="lager">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="text" name="name" value="<?= h($l['name']) ?>" maxlength="60" required autofocus>
            <button class="btn btn--primaer btn--klein" type="submit" title="Speichern">✓</button>
            <a class="btn btn--klein" href="<?= h(orte_sel_url($lagerId, $regalId)) ?>">Abbrechen</a>
          </form>
        <?php else: ?>
          <div class="orte-zeile">
            <a class="orte-eintrag<?= $id === $lagerId ? ' is-active' : '' ?>" href="orte.php?lager=<?= $id ?>">
              <span class="orte-name"><?= h($l['name']) ?></span>
              <span class="orte-meta"><?= $regale ?> Regal<?= $regale === 1 ? '' : 'e' ?></span>
              <span class="orte-pfeil">›</span>
            </a>
            <div class="orte-aktionen">
              <a class="orte-akt" href="<?= h(orte_edit_url('lager', $id, $lagerId, $regalId)) ?>"
                 title="Umbenennen" aria-label="Lager umbenennen">✎</a>
              <?php if ($darfLoeschen): ?>
                <?php if ($belegt > 0): ?>
                  <button class="orte-akt orte-akt--del" type="button" disabled
                          title="<?= $belegt ?> Kisten/Artikel eingelagert – erst umräumen">✕</button>
                <?php else: ?>
                  <form method="post" action="<?= h(orte_sel_url($lagerId, $regalId)) ?>"
                        onsubmit="return confirm(<?= h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="loeschen">
                    <input type="hidden" name="ebene" value="lager">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="orte-akt orte-akt--del" type="submit"
                            title="Löschen" aria-label="Lager löschen">✕</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
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
        <?php foreach ($regalListe as $r):
            $id = (int) $r['regal_id'];
            $imEdit = $editEbene === 'regal' && $editId === $id;
            $belegt = (int) $r['belegt'];
            $faecher = (int) $r['faecher'];
            $confirm = $faecher > 0
                ? 'Regal „' . $r['name'] . '" samt ' . ($faecher === 1 ? '1 Fach' : $faecher . ' Fächern') . ' löschen? Es ist nichts eingelagert.'
                : 'Regal „' . $r['name'] . '" löschen?';
        ?>
          <?php if ($imEdit): ?>
            <form method="post" action="<?= h(orte_sel_url($lagerId, $regalId)) ?>" class="orte-edit">
              <?= csrf_field() ?>
              <input type="hidden" name="aktion" value="umbenennen">
              <input type="hidden" name="ebene" value="regal">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="text" name="name" value="<?= h($r['name']) ?>" maxlength="60" required autofocus>
              <button class="btn btn--primaer btn--klein" type="submit" title="Speichern">✓</button>
              <a class="btn btn--klein" href="<?= h(orte_sel_url($lagerId, $regalId)) ?>">Abbrechen</a>
            </form>
          <?php else: ?>
            <div class="orte-zeile">
              <a class="orte-eintrag<?= $id === $regalId ? ' is-active' : '' ?>"
                 href="orte.php?lager=<?= $lagerId ?>&amp;regal=<?= $id ?>">
                <span class="orte-name"><?= h($r['name']) ?></span>
                <span class="orte-meta"><?= $faecher ?> Fach<?= $faecher === 1 ? '' : 'er' ?></span>
                <span class="orte-pfeil">›</span>
              </a>
              <div class="orte-aktionen">
                <a class="orte-akt" href="<?= h(orte_edit_url('regal', $id, $lagerId, $regalId)) ?>"
                   title="Umbenennen" aria-label="Regal umbenennen">✎</a>
                <?php if ($darfLoeschen): ?>
                  <?php if ($belegt > 0): ?>
                    <button class="orte-akt orte-akt--del" type="button" disabled
                            title="<?= $belegt ?> Kisten/Artikel eingelagert – erst umräumen">✕</button>
                  <?php else: ?>
                    <form method="post" action="<?= h(orte_sel_url($lagerId, $regalId)) ?>"
                          onsubmit="return confirm(<?= h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) ?>);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="aktion" value="loeschen">
                      <input type="hidden" name="ebene" value="regal">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="orte-akt orte-akt--del" type="submit"
                              title="Löschen" aria-label="Regal löschen">✕</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
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
            $id = (int) $f['fach_id'];
            $imEdit = $editEbene === 'fach' && $editId === $id;
            $belegt = (int) $f['kisten'] + (int) $f['artikel'];
            $confirm = 'Fach „' . $f['name'] . '" löschen?';
        ?>
          <?php if ($imEdit): ?>
            <form method="post" action="<?= h(orte_sel_url($lagerId, $regalId)) ?>" class="orte-edit">
              <?= csrf_field() ?>
              <input type="hidden" name="aktion" value="umbenennen">
              <input type="hidden" name="ebene" value="fach">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="text" name="name" value="<?= h($f['name']) ?>" maxlength="60" required autofocus>
              <button class="btn btn--primaer btn--klein" type="submit" title="Speichern">✓</button>
              <a class="btn btn--klein" href="<?= h(orte_sel_url($lagerId, $regalId)) ?>">Abbrechen</a>
            </form>
          <?php else: ?>
            <div class="orte-zeile">
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
              <div class="orte-aktionen">
                <a class="orte-akt" href="<?= h(orte_edit_url('fach', $id, $lagerId, $regalId)) ?>"
                   title="Umbenennen" aria-label="Fach umbenennen">✎</a>
                <?php if ($darfLoeschen): ?>
                  <?php if ($belegt > 0): ?>
                    <button class="orte-akt orte-akt--del" type="button" disabled
                            title="<?= $belegt ?> Kisten/Artikel eingelagert – erst umräumen">✕</button>
                  <?php else: ?>
                    <form method="post" action="<?= h(orte_sel_url($lagerId, $regalId)) ?>"
                          onsubmit="return confirm(<?= h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) ?>);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="aktion" value="loeschen">
                      <input type="hidden" name="ebene" value="fach">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="orte-akt orte-akt--del" type="submit"
                              title="Löschen" aria-label="Fach löschen">✕</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
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
