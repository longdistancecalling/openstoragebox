<?php
declare(strict_types=1);

/** Escaping für jede Ausgabe im HTML-Kontext. Kurzer Name, weil sehr oft gebraucht. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Adresse einer Datei unter assets/ mit Änderungszeit als Version. Ändert
 * sich die Datei, ändert sich die Adresse, und der Browser lädt sie neu –
 * sonst zeigt er nach einem Update noch die alte Fassung aus dem Cache,
 * und z. B. neue Designs fehlen, bis jemand Strg+F5 drückt.
 */
function asset(string $datei): string
{
    $zeit = @filemtime(__DIR__ . '/../assets/' . $datei);
    return 'assets/' . $datei . '?v=' . ($zeit ?: 0);
}

/** CSRF-Token der Session, wird beim ersten Aufruf erzeugt. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Verstecktes Formularfeld mit dem Token. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Prüft das mitgesendete Token. Bricht bei Fehlschlag hart ab. */
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Ungültiges Sicherheitstoken. Bitte die Seite neu laden und noch einmal absenden.');
    }
}

/** Meldung für die nächste Seite hinterlegen (Post/Redirect/Get). */
function flash(string $text, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['text' => $text, 'type' => $type];
}

/** Meldungen abholen und aus der Session entfernen. */
function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Weiterleiten und Skript beenden. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Datum als TT.MM.JJJJ, leer wenn nicht gesetzt. */
function datum_de(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('d.m.Y') : $iso;
}

/** Klartext für den Fälligkeitstyp. */
function faellig_label(?string $typ): string
{
    return match ($typ) {
        'ablauf'   => 'Läuft ab',
        'pruefung' => 'Prüfung',
        default    => '',
    };
}

/**
 * Ampelstufe für eine Fälligkeit.
 * null = kein Datum, 'ok' | 'bald' (<= 30 Tage) | 'faellig' (überschritten)
 */
function faellig_stufe(?int $tage): ?string
{
    if ($tage === null) {
        return null;
    }
    if ($tage < 0) {
        return 'faellig';
    }
    // Vorwarnzeit ist einstellbar; ohne Einstellung 30 Tage.
    if ($tage <= (defined('WARNUNG_TAGE') ? WARNUNG_TAGE : 30)) {
        return 'bald';
    }
    return 'ok';
}

/** Baut eine URL mit geänderten Query-Parametern, alle anderen bleiben erhalten. */
function url_mit(array $aenderungen): string
{
    $q = array_merge($_GET, $aenderungen);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
}

/**
 * Der Lagerort-Chip – funktioniert für Kisten und für freie Standorte.
 */
function lagerort(array $row): string
{
    if (!empty($row['kiste_nr'])) {
        return '<span class="platz">'
             . '<span class="platz-kiste">' . h($row['kiste_nr']) . '</span>'
             . '<span class="platz-pfad">'
             . h($row['lager']) . '<i>/</i>' . h($row['regal']) . '<i>/</i>' . h($row['fach'])
             . '</span></span>';
    }

    // Direkt in einem Fach abgelegt (keine Kiste): es gibt keinen
    // Kistenaufkleber, aber Lager/Regal/Fach zeigen den Platz genau an.
    if (!empty($row['fach'])) {
        return '<span class="platz">'
             . '<span class="platz-frei">im Fach</span>'
             . '<span class="platz-pfad">'
             . h($row['lager']) . '<i>/</i>' . h($row['regal']) . '<i>/</i>' . h($row['fach'])
             . '</span></span>';
    }

    if (!empty($row['standort_frei'])) {
        return '<span class="platz">'
             . '<span class="platz-frei">ohne Kiste</span>'
             . '<span class="platz-pfad">' . h($row['standort_frei']) . '</span>'
             . '</span>';
    }

    return '<span class="platz"><span class="platz-leer">kein Lagerort</span></span>';
}

/** Rückwärtskompatibler Name aus v1, wird für Kistenlisten weiter genutzt. */
function lagerplatz(array $row): string
{
    return lagerort($row);
}

/** Klartext eines Entnahmegrunds. */
function grund_label(?string $grund): string
{
    return ENTNAHME_GRUENDE[$grund] ?? (string) $grund;
}

/**
 * Nächste freie Inventarnummer aus der Artikel-ID.
 * Wird nach dem INSERT gesetzt, damit die Nummer garantiert eindeutig
 * ist – ein vorher berechnetes MAX()+1 könnte bei zwei gleichzeitigen
 * Anlagen zweimal dieselbe Nummer liefern.
 */
function inventar_nr_aus_id(int $artikelId): string
{
    return INVENTAR_PREFIX . str_pad((string) $artikelId, 5, '0', STR_PAD_LEFT);
}

/**
 * Sendet ein Array als CSV zum Download.
 *
 * Semikolon als Trenner und ein UTF-8-BOM voran: nur so öffnet Excel
 * unter Windows die Datei mit korrekten Umlauten und in Spalten
 * getrennt. LibreOffice kommt mit beidem ebenfalls zurecht.
 */
function csv_ausgeben(string $dateiname, array $kopf, array $zeilen): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dateiname . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $kopf, ';', '"', '');
    foreach ($zeilen as $z) {
        fputcsv($out, $z, ';', '"', '');
    }
    fclose($out);
    exit;
}

/** Klartext eines Zugangsgrunds. */
function zugang_label(?string $grund): string
{
    return ZUGANG_GRUENDE[$grund] ?? (string) $grund;
}

/**
 * Reduziert eine gescannte Eingabe auf Ziffern.
 * Handscanner hängen gern Leerzeichen oder Bindestriche an.
 */
function ean_saeubern(string $roh): string
{
    return preg_replace('/\D+/', '', $roh) ?? '';
}

/**
 * Prüft die EAN-Prüfziffer (EAN-8, EAN-13, UPC-A mit 12 Stellen).
 *
 * Jede zweite Ziffer von rechts zählt dreifach, die Summe muss ohne
 * Rest durch 10 teilbar sein. Ein Tippfehler fällt damit sofort auf –
 * beim Scannen passiert das selten, beim Abtippen ständig.
 */
function ean_gueltig(string $ean): bool
{
    $len = strlen($ean);
    if (!in_array($len, [8, 12, 13, 14], true) || !ctype_digit($ean)) {
        return false;
    }
    $summe = 0;
    // Von rechts nach links, beginnend bei der Stelle vor der Prüfziffer.
    for ($i = $len - 2, $faktor = 3; $i >= 0; $i--, $faktor = 4 - $faktor) {
        $summe += (int) $ean[$i] * $faktor;
    }
    return (10 - $summe % 10) % 10 === (int) $ean[$len - 1];
}

/** EAN gruppiert darstellen, damit sie sich vorlesen lässt. */
function ean_anzeigen(?string $ean): string
{
    if (!$ean) {
        return '';
    }
    return strlen($ean) === 13
        ? substr($ean, 0, 1) . ' ' . substr($ean, 1, 6) . ' ' . substr($ean, 7)
        : $ean;
}

/**
 * Rendert ein Eingabefeld für eine Felddefinition.
 * $wert ist der aktuell gespeicherte Wert (oder '').
 */
function feld_eingabe(array $feld, string $wert): string
{
    $name = 'feld_' . (int) $feld['feld_id'];
    $id   = h($name);
    $pf   = $feld['pflicht'] ? ' required' : '';

    switch ($feld['typ']) {
        case 'zahl':
            return '<input type="number" step="any" id="' . $id . '" name="' . $id . '" '
                 . 'value="' . h($wert) . '"' . $pf . '>';

        case 'datum':
            return '<input type="date" id="' . $id . '" name="' . $id . '" '
                 . 'value="' . h($wert) . '"' . $pf . '>';

        case 'ja_nein':
            $html = '<select id="' . $id . '" name="' . $id . '"' . $pf . '>'
                  . '<option value="">– keine Angabe –</option>';
            foreach (['ja' => 'Ja', 'nein' => 'Nein'] as $w => $l) {
                $html .= '<option value="' . $w . '"' . ($wert === $w ? ' selected' : '') . '>' . $l . '</option>';
            }
            return $html . '</select>';

        case 'auswahl':
            $html = '<select id="' . $id . '" name="' . $id . '"' . $pf . '>'
                  . '<option value="">– bitte wählen –</option>';
            foreach (explode('|', (string) $feld['optionen']) as $opt) {
                $opt = trim($opt);
                if ($opt === '') {
                    continue;
                }
                $html .= '<option value="' . h($opt) . '"' . ($wert === $opt ? ' selected' : '') . '>'
                       . h($opt) . '</option>';
            }
            return $html . '</select>';

        default:
            return '<input type="text" maxlength="255" id="' . $id . '" name="' . $id . '" '
                 . 'value="' . h($wert) . '"' . $pf . '>';
    }
}

/**
 * Prüft einen eingegebenen Feldwert. Gibt Fehlertext oder null zurück.
 *
 * Achtung bei den Meldungen: PHP lässt Bytes ab 0x80 in Variablennamen zu.
 * In "„$name“" liest der Parser deshalb $name“ als Variablennamen – das
 * typografische Schlusszeichen gehört mit dazu – und setzt einen leeren
 * String ein. Geschweifte Klammern um die Variable lösen das.
 */
function feld_pruefen(array $feld, string $wert): ?string
{
    $name = $feld['bezeichnung'];

    if ($wert === '') {
        return $feld['pflicht'] ? "Das Pflichtfeld „{$name}“ ist leer." : null;
    }
    if ($feld['typ'] === 'zahl' && !is_numeric(str_replace(',', '.', $wert))) {
        return "„{$name}“ muss eine Zahl sein.";
    }
    if ($feld['typ'] === 'datum' && !DateTimeImmutable::createFromFormat('Y-m-d', $wert)) {
        return "„{$name}“ enthält kein gültiges Datum.";
    }
    if ($feld['typ'] === 'auswahl') {
        $erlaubt = array_map('trim', explode('|', (string) $feld['optionen']));
        if (!in_array($wert, $erlaubt, true)) {
            return "„{$name}“ enthält keinen der zulässigen Werte.";
        }
    }
    if ($feld['typ'] === 'ja_nein' && !in_array($wert, ['ja', 'nein'], true)) {
        return "„{$name}“ muss Ja oder Nein sein.";
    }
    return null;
}

/**
 * Inhalt eines QR-Codes für eine Kiste oder einen Artikel.
 *
 * Mit gesetzter BASIS_URL entsteht eine vollständige Adresse – ein
 * Scan mit der Handykamera öffnet dann direkt die Buchungsseite.
 * Ohne sie bleibt die reine Kennung, die die Scan-Seite ebenfalls
 * versteht.
 */
function qr_inhalt(string $art, string $kennung): string
{
    $praefix = match ($art) {
        'kiste'  => 'K:',
        'regal'  => 'R:',
        'beleg'  => 'E:',
        default  => 'A:',
    };
    $code = $praefix . $kennung;
    return BASIS_URL !== ''
        ? rtrim(BASIS_URL, '/') . '/scan.php?c=' . rawurlencode($code)
        : $code;
}

/** Fertiges SVG für ein Etikett. Gibt bei zu langem Inhalt einen leeren String zurück. */
function qr_svg(string $art, string $kennung, int $px = 90): string
{
    require_once __DIR__ . '/qr.php';
    try {
        return QrCode::svg(qr_inhalt($art, $kennung), $px);
    } catch (Throwable $e) {
        error_log('QR: ' . $e->getMessage());
        return '';
    }
}

/* =====================================================================
   Verpackungseinheiten
   ===================================================================== */

/**
 * Bestand als lesbarer Text.
 * Ohne Gebinde: "25 Stück".
 * Mit Gebinde:  "8 Kisten + 7 Flaschen = 167 Flaschen".
 */
function bestand_text(array $a): string
{
    $menge = (int) $a['menge'];
    if (empty($a['ve_inhalt'])) {
        return $menge . ' Stück';
    }

    $gebinde = $a['gebinde_einheit'] ?: 'Gebinde';
    $einzeln = $a['ve_einheit'] ?: 'Stück';
    $lose    = (int) $a['lose'];

    $text = $menge . ' ' . ($menge === 1 ? $gebinde : mehrzahl($gebinde));
    if ($lose > 0) {
        $text .= ' + ' . $lose . ' ' . $einzeln;
    }
    return $text . ' = ' . (int) $a['gesamt_einzeln'] . ' ' . $einzeln;
}

/**
 * Grobe Mehrzahlbildung für die paar Gebindewörter, die vorkommen.
 * Keine allgemeine Sprachlogik – nur so viel, dass "8 Kisten" statt
 * "8 Kiste" dasteht.
 */
function mehrzahl(string $wort): string
{
    return match (mb_strtolower($wort)) {
        'kiste'    => 'Kisten',
        'karton'   => 'Kartons',
        'palette'  => 'Paletten',
        'packung'  => 'Packungen',
        'sack'     => 'Säcke',
        'rolle'    => 'Rollen',
        'gebinde', 'paar', 'set' => $wort,
        default    => $wort,
    };
}

/**
 * Bucht eine Bestandsänderung auf Gebinde und lose Stücke.
 *
 * Der interessante Fall ist die Entnahme einzelner Stücke aus einem
 * vollen Gebinde: reichen die losen nicht, wird ein Gebinde
 * aufgebrochen – genau wie im Regal, wo jemand eine Kiste öffnet.
 *
 * Gibt [neue_menge, neue_lose] zurück oder wirft bei zu wenig Bestand.
 */
function bestand_rechnen(array $a, int $anzahl, string $einheit, bool $zugang): array
{
    $menge = (int) $a['menge'];
    $lose  = (int) $a['lose'];
    $ve    = (int) ($a['ve_inhalt'] ?? 0);

    // Ohne Gebinde ist alles eine einfache Addition.
    if ($ve < 1) {
        $neu = $zugang ? $menge + $anzahl : $menge - $anzahl;
        if ($neu < 0) {
            throw new RuntimeException("Es sind nur $menge Stück im Bestand, gebucht werden sollen $anzahl.");
        }
        return [$neu, 0];
    }

    if ($einheit === 'gebinde') {
        $neu = $zugang ? $menge + $anzahl : $menge - $anzahl;
        if ($neu < 0) {
            $g = $a['gebinde_einheit'] ?: 'Gebinde';
            throw new RuntimeException(
                'Es sind nur ' . $menge . ' ' . ($menge === 1 ? $g : mehrzahl($g))
                . " im Bestand, gebucht werden sollen $anzahl."
            );
        }
        return [$neu, $lose];
    }

    // Einzelstücke
    if ($zugang) {
        $lose += $anzahl;
        // Volle Gebinde daraus bilden, damit die Zählung sauber bleibt.
        $menge += intdiv($lose, $ve);
        $lose   = $lose % $ve;
        return [$menge, $lose];
    }

    $gesamt = $menge * $ve + $lose;
    if ($anzahl > $gesamt) {
        $e = $a['ve_einheit'] ?: 'Stück';
        throw new RuntimeException("Es sind nur $gesamt $e im Bestand, entnommen werden sollen $anzahl.");
    }
    $rest   = $gesamt - $anzahl;
    return [intdiv($rest, $ve), $rest % $ve];
}

/** Klartext der Buchungseinheit für Protokoll und Schein. */
function einheit_text(array $a, string $einheit, int $anzahl): string
{
    if (empty($a['ve_inhalt']) || $einheit === 'einzeln') {
        return $anzahl . ' ' . ($a['ve_einheit'] ?: 'Stück');
    }
    $g = $a['gebinde_einheit'] ?: 'Gebinde';
    return $anzahl . ' ' . ($anzahl === 1 ? $g : mehrzahl($g))
         . ' (' . ($anzahl * (int) $a['ve_inhalt']) . ' ' . ($a['ve_einheit'] ?: 'Stück') . ')';
}

/**
 * Eine Anzahl Einzelstücke als Text, z. B. für "noch offen".
 * Geht sie glatt in Gebinden auf, steht das Gebinde vorn ("2 Kisten
 * (40 Flaschen)"), sonst nur die Stückzahl ("27 Flaschen").
 */
function einzeln_text(array $a, int $einzeln): string
{
    $ve = (int) ($a['ve_inhalt'] ?? 0);
    if ($ve > 1 && $einzeln > 0 && $einzeln % $ve === 0) {
        return einheit_text($a, 'gebinde', intdiv($einzeln, $ve));
    }
    return einheit_text($a, 'einzeln', $einzeln);
}

/** Kann zu einer Entnahme mit diesem Grund eine Rückgabe gebucht werden? */
function rueckgabe_moeglich(string $grund): bool
{
    return in_array($grund, RUECKGABE_GRUENDE, true);
}

/** Belegnummer einer Entnahme, wie sie auf Schein und QR-Code steht. */
function beleg_nr(int $entnahmeId): string
{
    return 'E-' . str_pad((string) $entnahmeId, 5, '0', STR_PAD_LEFT);
}


/* =====================================================================
   Scan-Codes
   ===================================================================== */

/**
 * Zerlegt eine gescannte oder getippte Eingabe in Art und Kennung.
 *
 * Versteht die eigenen QR-Codes (K:/A:/R:/E:), eine Hersteller-EAN und
 * eine vollständige URL – Letzteres, falls jemand den gescannten Text
 * aus einer Handy-Kamera-App einfügt.
 *
 * @return array{art:string, wert:string}  art: kiste|artikel|regal|beleg|ean|leer
 */
function code_deuten(string $roh): array
{
    $roh = trim($roh);
    if ($roh === '') {
        return ['art' => 'leer', 'wert' => ''];
    }

    // Vollständige URL: den Parameter c herausziehen.
    if (preg_match('#^https?://#i', $roh)) {
        $teile = parse_url($roh);
        parse_str($teile['query'] ?? '', $q);
        $roh = trim((string) ($q['c'] ?? ''));
        if ($roh === '') {
            return ['art' => 'leer', 'wert' => ''];
        }
    }

    if (preg_match('/^K:(.+)$/i', $roh, $m)) {
        return ['art' => 'kiste', 'wert' => trim($m[1])];
    }
    if (preg_match('/^A:(.+)$/i', $roh, $m)) {
        return ['art' => 'artikel', 'wert' => trim($m[1])];
    }
    if (preg_match('/^R:(.+)$/i', $roh, $m)) {
        return ['art' => 'regal', 'wert' => trim($m[1])];
    }
    if (preg_match('/^E:(.+)$/i', $roh, $m)) {
        return ['art' => 'beleg', 'wert' => trim($m[1])];
    }

    // Alles andere als EAN behandeln – nur Ziffern übrig lassen.
    $ziffern = ean_saeubern($roh);
    return $ziffern !== ''
        ? ['art' => 'ean', 'wert' => $ziffern]
        : ['art' => 'leer', 'wert' => ''];
}


/* =====================================================================
   Lagerort-Auswahl (v10)
   ===================================================================== */

/**
 * Lädt alle Fächer als flache Liste mit vollem Pfad, für Auswahlfelder.
 * Nach Lager, Regal, Fach sortiert, damit das Dropdown der Struktur folgt.
 */
function faecher_liste(PDO $pdo): array
{
    return $pdo->query(
        'SELECT fach_id, lager, regal, fach, pfad
         FROM v_fach
         ORDER BY s_lager, lager, s_regal, LENGTH(regal), regal, s_fach, LENGTH(fach), fach'
    )->fetchAll();
}

/**
 * Ein <select> für die Fachauswahl, gruppiert nach Lager und Regal.
 * $aktuell ist die vorgewählte fach_id (oder '').
 */
function fach_auswahl(array $faecher, string $name, string $aktuell, bool $pflicht = false): string
{
    $html = '<select name="' . h($name) . '" id="' . h($name) . '"' . ($pflicht ? ' required' : '') . '>';
    if (!$pflicht) {
        $html .= '<option value="">– kein Fach –</option>';
    }
    $lager = null; $regal = null; $offen = false;
    foreach ($faecher as $f) {
        $lr = $f['lager'] . ' / ' . $f['regal'];
        if ($lr !== ($lager . ' / ' . $regal)) {
            if ($offen) { $html .= '</optgroup>'; }
            $html .= '<optgroup label="' . h($lr) . '">';
            $offen = true;
            $lager = $f['lager']; $regal = $f['regal'];
        }
        $sel = $aktuell === (string) $f['fach_id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $f['fach_id'] . '"' . $sel . '>' . h($f['fach']) . '</option>';
    }
    if ($offen) { $html .= '</optgroup>'; }
    $html .= '</select>';
    return $html;
}

/**
 * Findet die fach_id zu einer Lager/Regal/Fach-Kombination und legt
 * fehlende Ebenen dabei an. Für den CSV-Import gedacht, wo Orte als
 * Text kommen. Gibt null zurück, wenn nicht alle drei Angaben da sind.
 *
 * $zwischen puffert schon aufgelöste Kombinationen innerhalb eines
 * Importlaufs, damit dieselbe Kombination nicht mehrfach gesucht wird.
 */
function fach_id_aufloesen(PDO $pdo, string $lager, string $regal, string $fach, array &$zwischen): ?int
{
    $lager = trim($lager); $regal = trim($regal); $fach = trim($fach);
    if ($lager === '' || $regal === '' || $fach === '') {
        return null;
    }
    $schluessel = $lager . '|' . $regal . '|' . $fach;
    if (isset($zwischen[$schluessel])) {
        return $zwischen[$schluessel];
    }

    // Lager
    $stmt = $pdo->prepare('SELECT lager_id FROM lager WHERE name = ?');
    $stmt->execute([$lager]);
    $lagerId = $stmt->fetchColumn();
    if ($lagerId === false) {
        $pdo->prepare('INSERT INTO lager (name) VALUES (?)')->execute([$lager]);
        $lagerId = (int) $pdo->lastInsertId();
    }

    // Regal
    $stmt = $pdo->prepare('SELECT regal_id FROM regal WHERE lager_id = ? AND name = ?');
    $stmt->execute([$lagerId, $regal]);
    $regalId = $stmt->fetchColumn();
    if ($regalId === false) {
        $pdo->prepare('INSERT INTO regal (lager_id, name) VALUES (?, ?)')->execute([$lagerId, $regal]);
        $regalId = (int) $pdo->lastInsertId();
    }

    // Fach
    $stmt = $pdo->prepare('SELECT fach_id FROM fach WHERE regal_id = ? AND name = ?');
    $stmt->execute([$regalId, $fach]);
    $fachId = $stmt->fetchColumn();
    if ($fachId === false) {
        $pdo->prepare('INSERT INTO fach (regal_id, name) VALUES (?, ?)')->execute([$regalId, $fach]);
        $fachId = (int) $pdo->lastInsertId();
    }

    $zwischen[$schluessel] = (int) $fachId;
    return (int) $fachId;
}


/* =====================================================================
   Darstellung: Farben und Schrift pro Gerät
   ===================================================================== */

/**
 * Die gewählte Darstellung aus dem Cookie, ['farbe' => …, 'schrift' => …].
 * Unbekannte Werte fallen auf den Standard zurück.
 */
function darstellung(): array
{
    [$farbe, $schrift] = array_pad(explode('.', (string) ($_COOKIE[DARSTELLUNG_COOKIE] ?? ''), 2), 2, '');
    return [
        'farbe'   => isset(DARSTELLUNG_FARBEN[$farbe])      ? $farbe   : array_key_first(DARSTELLUNG_FARBEN),
        'schrift' => isset(DARSTELLUNG_SCHRIFTEN[$schrift]) ? $schrift : array_key_first(DARSTELLUNG_SCHRIFTEN),
    ];
}

/**
 * Attribute für das <html>-Element. Das Stylesheet hängt Farben und
 * Schrift daran auf; serverseitig gesetzt, damit nichts kurz im
 * falschen Design aufblitzt.
 */
function darstellung_attribute(): string
{
    $d = darstellung();
    return ' data-farbe="' . h($d['farbe']) . '" data-schrift="' . h($d['schrift']) . '"';
}

/**
 * Zusätzliches für den <head>: Lexend kommt von Google Fonts (wie die
 * Standardschriften), OpenDyslexic liegt in assets/fonts. color-scheme
 * sorgt im dunklen Design für dunkle Datumsauswahl und Rollbalken.
 */
function darstellung_kopf(): string
{
    $d    = darstellung();
    $html = '';
    if (in_array($d['farbe'], DARSTELLUNG_DUNKLE, true)) {
        $html .= '<meta name="color-scheme" content="dark">' . "\n";
    }
    if ($d['schrift'] === 'lexend') {
        $html .= '<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n";
    }
    // Rotes Plus (nach dem DRK-Styleguide): Merriweather ist dort die Hausschrift
    // für Überschriften. Nur mit der Standardschrift – eine gewählte
    // Leseschrift hat Vorrang.
    if ($d['farbe'] === 'rotesplus' && $d['schrift'] === 'standard') {
        $html .= '<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@300;700&display=swap" rel="stylesheet">' . "\n";
    }
    return $html;
}

/**
 * Link, der eine Einstellung ändert und auf die aktuelle Seite
 * zurückführt. $aenderung ist ['farbe' => …] oder ['schrift' => …].
 */
function darstellung_link(array $aenderung): string
{
    $zurueck = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    if (($_SERVER['QUERY_STRING'] ?? '') !== '') {
        $zurueck .= '?' . $_SERVER['QUERY_STRING'];
    }
    return 'darstellung.php?' . http_build_query($aenderung + ['zurueck' => $zurueck]);
}

/**
 * Der Hell/Dunkel-Schalter für die Kopfleiste. Ein Link statt eines
 * Formulars: geht ohne Skript, ein Klick schaltet um und führt auf die
 * aktuelle Seite zurück. Alle dunklen Designs (DARSTELLUNG_DUNKLE)
 * gelten als "an", ein Klick führt von dort zu Hell; von jedem hellen
 * Design zu Dunkel. Die übrigen Designs stehen unter Verwaltung →
 * Darstellung.
 * $klasse ist das CSS-Präfix ("hell-dunkel" bzw. "t-hell-dunkel").
 */
function hell_dunkel_schalter(string $klasse = 'hell-dunkel'): void
{
    $dunkel = in_array(darstellung()['farbe'], DARSTELLUNG_DUNKLE, true);
    ?>
    <a class="<?= h($klasse) ?>" role="switch" aria-checked="<?= $dunkel ? 'true' : 'false' ?>"
       href="<?= h(darstellung_link(['farbe' => $dunkel ? 'hell' : 'dunkel'])) ?>"
       title="<?= $dunkel ? 'Helles Design einschalten' : 'Dunkles Design einschalten' ?>">
      <span class="<?= h($klasse) ?>-zeichen" aria-hidden="true">☾</span>
      <span class="<?= h($klasse) ?>-text">Dunkel</span>
      <span class="<?= h($klasse) ?>-spur" aria-hidden="true"></span>
    </a>
    <?php
}


/**
 * Die drei Listen der Seite Bedarf: überfällig (abgelaufen oder Prüfung
 * verpasst), unter Sollbestand, bald fällig (Zeitraum aus den
 * Einstellungen). Auch das Terminal zeigt sie, deshalb an einer Stelle.
 */
function bedarf_laden(PDO $pdo): array
{
    $ueberfaellig = $pdo->query(
        'SELECT * FROM v_artikel
          WHERE faellig_am IS NOT NULL AND tage_bis_faellig < 0
          ORDER BY faellig_am'
    )->fetchAll();

    $unterdeckung = $pdo->query(
        'SELECT * FROM v_artikel
          WHERE unter_soll = 1
          ORDER BY (fehlmenge / NULLIF(soll_bestand,0)) DESC, artikelname'
    )->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT * FROM v_artikel
          WHERE faellig_am IS NOT NULL AND tage_bis_faellig >= 0 AND tage_bis_faellig <= ?
          ORDER BY faellig_am'
    );
    $stmt->execute([WARNUNG_TAGE]);

    return [
        'ueberfaellig' => $ueberfaellig,
        'unterdeckung' => $unterdeckung,
        'bald'         => $stmt->fetchAll(),
    ];
}


/* =====================================================================
   MHD-Chargen (v12)
   =====================================================================
   Ein Artikel mit mit_chargen = 1 teilt seinen Gesamtbestand nach MHD
   auf. Die Tabelle charge zählt Einzelstücke; artikel.menge/lose
   bleiben der Gesamtbestand. Jede Bestandsbuchung ruft deshalb nach
   bestand_rechnen() noch chargen_mitbuchen() auf – für Artikel ohne
   Chargen tut die nichts.
   ===================================================================== */

/** Eine Buchungsmenge in Einzelstücken. */
function stueckzahl(array $art, int $menge, string $einheit): int
{
    return $einheit === 'gebinde' && !empty($art['ve_inhalt'])
         ? $menge * (int) $art['ve_inhalt']
         : $menge;
}

/** Die Chargen eines Artikels, frühestes MHD zuerst. */
function chargen_laden(PDO $pdo, int $artikelId): array
{
    $stmt = $pdo->prepare('SELECT mhd, menge FROM charge WHERE artikel_id = ? ORDER BY mhd');
    $stmt->execute([$artikelId]);
    return $stmt->fetchAll();
}

/** Ein MHD aus einem Formularfeld (JJJJ-MM-TT), sonst null. */
function mhd_aus_eingabe(string $wert): ?string
{
    $wert = trim($wert);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
    return $d && $d->format('Y-m-d') === $wert ? $wert : null;
}

/**
 * Bucht Einzelstücke auf eine Charge (positiv) oder von ihr ab
 * (negativ). Fehlt die Charge, wird sie angelegt; wird sie leer,
 * verschwindet sie.
 */
function charge_buchen(PDO $pdo, array $art, int $artikelId, string $mhd, int $einzeln): void
{
    if ($einzeln > 0) {
        $pdo->prepare(
            'INSERT INTO charge (artikel_id, mhd, menge) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE menge = menge + VALUES(menge)'
        )->execute([$artikelId, $mhd, $einzeln]);
        return;
    }

    $ab   = -$einzeln;
    $stmt = $pdo->prepare('SELECT menge FROM charge WHERE artikel_id = ? AND mhd = ? FOR UPDATE');
    $stmt->execute([$artikelId, $mhd]);
    $vorhanden = $stmt->fetchColumn();
    if ($vorhanden === false) {
        throw new RuntimeException('Eine Charge mit MHD ' . datum_de($mhd) . ' gibt es bei diesem Artikel nicht.');
    }
    if ((int) $vorhanden < $ab) {
        throw new RuntimeException(
            'In der Charge mit MHD ' . datum_de($mhd) . ' sind nur ' . einheit_text($art, 'einzeln', (int) $vorhanden)
            . ', entnommen werden sollen ' . einheit_text($art, 'einzeln', $ab) . '.'
        );
    }
    if ((int) $vorhanden === $ab) {
        $pdo->prepare('DELETE FROM charge WHERE artikel_id = ? AND mhd = ?')->execute([$artikelId, $mhd]);
    } else {
        $pdo->prepare('UPDATE charge SET menge = menge - ? WHERE artikel_id = ? AND mhd = ?')
            ->execute([$ab, $artikelId, $mhd]);
    }
}

/**
 * Bucht von den ältesten Chargen ab, notfalls über mehrere hinweg.
 * Gibt das älteste betroffene MHD zurück.
 */
function chargen_fifo_abbuchen(PDO $pdo, array $art, int $artikelId, int $einzeln): ?string
{
    $erstes = null;
    foreach (chargen_laden($pdo, $artikelId) as $c) {
        if ($einzeln < 1) {
            break;
        }
        $nimm = min($einzeln, (int) $c['menge']);
        charge_buchen($pdo, $art, $artikelId, $c['mhd'], -$nimm);
        $erstes ??= $c['mhd'];
        $einzeln -= $nimm;
    }
    return $erstes;
}

/**
 * Setzt das Datum eines Chargen-Artikels auf das früheste MHD, von dem
 * noch etwas da ist – oder leert es, wenn keine Charge mehr übrig ist.
 * Damit warnen Bedarf und Fälligkeiten, ohne von Chargen zu wissen.
 */
function chargen_mhd_setzen(PDO $pdo, int $artikelId): void
{
    $pdo->prepare(
        "UPDATE artikel
            SET faellig_am  = (SELECT MIN(c.mhd) FROM charge c WHERE c.artikel_id = ?),
                faellig_typ = IF((SELECT MIN(c.mhd) FROM charge c WHERE c.artikel_id = ?) IS NULL, NULL, 'ablauf')
          WHERE artikel_id = ? AND mit_chargen = 1"
    )->execute([$artikelId, $artikelId, $artikelId]);
}

/**
 * Bucht die Chargen zu einer Bestandsbuchung mit. Für Artikel ohne
 * Chargen tut sie nichts und gibt null zurück. $art braucht mit_chargen
 * und die Gebindefelder, $einzeln ist die Menge in Einzelstücken.
 *
 * Zugang: $mhd ist Pflicht – die Ware kommt mit ihrem MHD ins Lager.
 * Abgang: $mhd ist die ausgewählte Charge. null heißt "älteste zuerst",
 * für Buchungen ohne Auswahl.
 *
 * Gibt das MHD zurück, das ins Protokoll gehört.
 */
function chargen_mitbuchen(PDO $pdo, array $art, int $artikelId, int $einzeln, bool $zugang, ?string $mhd): ?string
{
    if (empty($art['mit_chargen'])) {
        return null;
    }
    if ($zugang) {
        if ($mhd === null) {
            throw new RuntimeException('Bitte das MHD der eingebuchten Ware angeben.');
        }
        charge_buchen($pdo, $art, $artikelId, $mhd, $einzeln);
    } elseif ($mhd !== null) {
        charge_buchen($pdo, $art, $artikelId, $mhd, -$einzeln);
    } else {
        $mhd = chargen_fifo_abbuchen($pdo, $art, $artikelId, $einzeln);
    }
    chargen_mhd_setzen($pdo, $artikelId);
    return $mhd;
}

/**
 * Gleicht die Chargen an den Gesamtbestand an, wenn der sich ohne
 * Angabe eines MHD geändert hat (Inventur, CSV-Import). Fehlmengen
 * gehen von den ältesten Chargen ab – was im Regal fehlt, ist meist
 * das, was zuerst hätte raus sollen. Mehrmengen kommen zur Charge mit
 * dem spätesten MHD.
 *
 * Gibt einen Hinweis zurück, wenn Bestand keiner Charge zugeordnet
 * werden konnte, weil es keine gab – sonst null.
 */
function chargen_angleichen(PDO $pdo, int $artikelId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT artikelname, menge, lose, ve_inhalt, ve_einheit, gebinde_einheit, mit_chargen
         FROM artikel WHERE artikel_id = ?'
    );
    $stmt->execute([$artikelId]);
    $art = $stmt->fetch();
    if (!$art || !$art['mit_chargen']) {
        return null;
    }

    $gesamt  = (int) $art['menge'] * max(1, (int) $art['ve_inhalt']) + (int) $art['lose'];
    $chargen = chargen_laden($pdo, $artikelId);
    $diff    = $gesamt - array_sum(array_map('intval', array_column($chargen, 'menge')));

    $hinweis = null;
    if ($diff < 0) {
        chargen_fifo_abbuchen($pdo, $art, $artikelId, -$diff);
    } elseif ($diff > 0) {
        if ($chargen) {
            charge_buchen($pdo, $art, $artikelId, end($chargen)['mhd'], $diff);
        } else {
            $hinweis = $art['artikelname'] . ': ' . einheit_text($art, 'einzeln', $diff)
                     . ' ohne MHD – bitte unter „Bearbeiten“ ein MHD zuordnen.';
        }
    }
    chargen_mhd_setzen($pdo, $artikelId);
    return $hinweis;
}

/**
 * Auswahlfeld für die Charge bei einer Buchung. Ohne $aktuell ist die
 * älteste vorausgewählt; jede Option zeigt MHD und Menge.
 */
function charge_auswahl(array $art, array $chargen, string $name, string $aktuell = ''): string
{
    $html = '<select name="' . h($name) . '" id="' . h($name) . '" required>';
    foreach ($chargen as $i => $c) {
        $sel = ($aktuell === '' ? $i === 0 : $aktuell === $c['mhd']) ? ' selected' : '';
        $html .= '<option value="' . h($c['mhd']) . '"' . $sel . '>MHD ' . h(datum_de($c['mhd']))
               . ' – ' . h(einzeln_text($art, (int) $c['menge'])) . '</option>';
    }
    return $html . '</select>';
}
