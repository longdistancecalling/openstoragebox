<?php
declare(strict_types=1);

/** Escaping für jede Ausgabe im HTML-Kontext. Kurzer Name, weil sehr oft gebraucht. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    if ($tage < 0)  return 'faellig';
    if ($tage <= 30) return 'bald';
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
    $code = ($art === 'kiste' ? 'K:' : 'A:') . $kennung;
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
