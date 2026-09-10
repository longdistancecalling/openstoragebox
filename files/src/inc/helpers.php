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
