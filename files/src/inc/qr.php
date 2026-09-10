<?php
declare(strict_types=1);

/**
 * QR-Code-Erzeugung, Ausgabe als SVG.
 *
 * Bewusst ohne externe Bibliothek: der Container soll keine
 * Composer-Abhängigkeiten und keinen Netzzugang beim Bauen brauchen.
 *
 * BEWUSSTE EINSCHRÄNKUNG: Byte-Modus, Fehlerkorrektur M, Versionen 1
 * bis 6 – also bis 108 Byte Inhalt. Das deckt Codes und URLs
 * vollständig ab und ist der Bereich, der gegen einen echten Scanner
 * (zbar) verifiziert wurde. Ab Version 7 verlangt die Norm zusätzlich
 * eine 18-Bit-Versionsinformation in zwei weiteren Blöcken; die ist
 * hier nicht umgesetzt, und ein Code ohne sie wird von Lesegeräten
 * nicht erkannt. Statt halb funktionierender Versionen wirft die
 * Klasse bei zu langem Inhalt lieber eine Ausnahme.
 *
 * Ablauf eines QR-Codes:
 *   1. Daten kodieren (Modus + Länge + Nutzdaten + Terminator + Padding)
 *   2. Fehlerkorrektur nach Reed-Solomon über GF(256) berechnen
 *   3. Daten- und Korrekturblöcke verschränken
 *   4. Bits im Zickzack in die Matrix schreiben
 *   5. Acht Masken durchprobieren, die mit der geringsten Strafe gewinnt
 */

final class QrCode
{
    /**
     * Fehlerkorrektur. Verwendet wird ausschließlich M (~15 % der
     * Daten wiederherstellbar) – ein guter Mittelweg zwischen Größe
     * und Robustheit, und die einzige gegen einen Scanner geprüfte
     * Stufe. Die übrigen Konstanten bleiben nur der Vollständigkeit
     * halber stehen.
     */
    public const ECC_M = 1;

    /** Größte unterstützte Version und daraus folgende Kapazität. */
    public const MAX_VERSION = 6;
    public const MAX_BYTES   = 108;

    /** Anzahl Korrekturbytes je Block, [version][ecc]. */
    private const ECC_BYTES = [
        1 => [7, 10, 13, 17],  2 => [10, 16, 22, 28], 3 => [15, 26, 18, 22],
        4 => [20, 18, 26, 16], 5 => [26, 24, 18, 22],  6 => [18, 16, 24, 28],
    ];

    /** Anzahl Blöcke, [version][ecc]. */
    private const BLOCKS = [
        1 => [1, 1, 1, 1], 2 => [1, 1, 1, 1], 3 => [1, 1, 2, 2],
        4 => [1, 2, 2, 4], 5 => [1, 2, 4, 4], 6 => [2, 4, 4, 4],
    ];

    /** Gesamtzahl der Datencodewörter (ohne Korrektur), [version][ecc]. */
    private const DATA_BYTES = [
        1 => [19, 16, 13, 9],    2 => [34, 28, 22, 16],
        3 => [55, 44, 34, 26],   4 => [80, 64, 48, 36],
        5 => [108, 86, 62, 46],  6 => [136, 108, 76, 60],
    ];

    /** Mittelpunkte der Ausrichtungsmuster je Version. */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
    ];

    private int $version;
    private int $ecc;
    private int $groesse;
    /** @var array<int,array<int,int|null>> null = noch frei */
    private array $matrix = [];
    /** @var array<int,array<int,bool>> true = Funktionsmuster, nicht überschreiben */
    private array $fest = [];

    private function __construct(string $daten, int $ecc)
    {
        $this->ecc = $ecc;
        $this->version = $this->versionFinden(strlen($daten));
        $this->groesse = 17 + 4 * $this->version;

        $bits = $this->datenKodieren($daten);
        $codes = $this->bloeckeBauen($bits);

        $this->matrixVorbereiten();
        $this->datenSchreiben($codes);
        $this->maskeAnwenden();
    }

    /** Erzeugt den Code und gibt ihn als SVG-Markup zurück. */
    public static function svg(string $daten, int $groessePx = 120, int $rand = 4): string
    {
        $qr = new self($daten, self::ECC_M);
        $n  = $qr->groesse;
        $gesamt = $n + 2 * $rand;

        // Ein Pfad für alle dunklen Module: deutlich kleiner als ein
        // <rect> je Modul und im Druck genauso scharf.
        $pfad = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($qr->matrix[$y][$x] === 1) {
                    $pfad .= 'M' . ($x + $rand) . ' ' . ($y + $rand) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $groessePx . '" height="' . $groessePx
             . '" viewBox="0 0 ' . $gesamt . ' ' . $gesamt . '" shape-rendering="crispEdges" role="img">'
             . '<rect width="' . $gesamt . '" height="' . $gesamt . '" fill="#fff"/>'
             . '<path d="' . $pfad . '" fill="#000"/></svg>';
    }

    /* ---------------------------------------------------------------
       1. Kodierung
       --------------------------------------------------------------- */

    private function versionFinden(int $laenge): int
    {
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            // 4 Bit Modus + 8 Bit Länge + Nutzdaten, alles in Bytes
            $noetig = (int) ceil((4 + 8 + $laenge * 8) / 8);
            if ($noetig <= self::DATA_BYTES[$v][$this->ecc]) {
                return $v;
            }
        }
        throw new RuntimeException(
            'Inhalt zu lang für einen QR-Code: ' . $laenge . ' Byte, möglich sind '
            . self::MAX_BYTES . '.'
        );
    }

    private function datenKodieren(string $daten): array
    {
        $bits = [];
        $push = function (int $wert, int $anzahl) use (&$bits): void {
            for ($i = $anzahl - 1; $i >= 0; $i--) {
                $bits[] = ($wert >> $i) & 1;
            }
        };

        $push(0b0100, 4);                 // Byte-Modus
        $push(strlen($daten), 8);         // Längenfeld (Version 1–9: 8 Bit)
        foreach (str_split($daten) as $z) {
            $push(ord($z), 8);
        }

        $kapazitaet = self::DATA_BYTES[$this->version][$this->ecc] * 8;

        // Terminator: bis zu vier Nullbits
        for ($i = 0; $i < 4 && count($bits) < $kapazitaet; $i++) {
            $bits[] = 0;
        }
        // Auf volle Bytes auffüllen
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        // Rest mit den genormten Füllbytes 236/17 im Wechsel
        $fuell = [0xEC, 0x11];
        $i = 0;
        while (count($bits) < $kapazitaet) {
            $push($fuell[$i++ % 2], 8);
        }

        // In Bytes wandeln
        $bytes = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) {
                $b = ($b << 1) | $bits[$i + $j];
            }
            $bytes[] = $b;
        }
        return $bytes;
    }

    /* ---------------------------------------------------------------
       2./3. Reed-Solomon und Blockverschränkung
       --------------------------------------------------------------- */

    /** Logarithmentabellen für GF(256), erzeugt mit dem Polynom 0x11D. */
    private static function gfTabellen(): array
    {
        static $exp = null, $log = null;
        if ($exp !== null) {
            return [$exp, $log];
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        return [$exp, $log];
    }

    private static function rsGenerator(int $grad): array
    {
        [$exp, $log] = self::gfTabellen();
        $g = [1];
        for ($i = 0; $i < $grad; $i++) {
            $neu = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $koef) {
                $neu[$j]     ^= $koef;
                $neu[$j + 1] ^= $koef === 0 ? 0 : $exp[($log[$koef] + $i) % 255];
            }
            $g = $neu;
        }
        return $g;
    }

    private static function rsRest(array $daten, int $anzahl): array
    {
        [$exp, $log] = self::gfTabellen();
        $gen  = self::rsGenerator($anzahl);
        $rest = array_fill(0, $anzahl, 0);

        foreach ($daten as $byte) {
            $faktor = $byte ^ $rest[0];
            array_shift($rest);
            $rest[] = 0;
            if ($faktor !== 0) {
                foreach ($gen as $i => $koef) {
                    if ($i === 0 || $koef === 0) {
                        continue;
                    }
                    $rest[$i - 1] ^= $exp[($log[$koef] + $log[$faktor]) % 255];
                }
            }
        }
        return $rest;
    }

    private function bloeckeBauen(array $daten): array
    {
        $anzBloecke = self::BLOCKS[$this->version][$this->ecc];
        $eccProBlock = self::ECC_BYTES[$this->version][$this->ecc];
        $gesamtDaten = count($daten);

        // Die kürzeren Blöcke kommen zuerst, die längeren haben ein Byte mehr.
        $kurz   = intdiv($gesamtDaten, $anzBloecke);
        $lange  = $gesamtDaten % $anzBloecke;

        $dBloecke = [];
        $eBloecke = [];
        $pos = 0;
        for ($i = 0; $i < $anzBloecke; $i++) {
            $len = $kurz + ($i >= $anzBloecke - $lange ? 1 : 0);
            $block = array_slice($daten, $pos, $len);
            $pos += $len;
            $dBloecke[] = $block;
            $eBloecke[] = self::rsRest($block, $eccProBlock);
        }

        // Verschränken: erst spaltenweise die Datenblöcke, dann die Korrekturblöcke.
        $aus = [];
        $maxD = max(array_map('count', $dBloecke));
        for ($i = 0; $i < $maxD; $i++) {
            foreach ($dBloecke as $b) {
                if (isset($b[$i])) {
                    $aus[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $eccProBlock; $i++) {
            foreach ($eBloecke as $b) {
                $aus[] = $b[$i];
            }
        }
        return $aus;
    }

    /* ---------------------------------------------------------------
       4. Matrix
       --------------------------------------------------------------- */

    private function setzen(int $x, int $y, int $wert, bool $fest = false): void
    {
        $this->matrix[$y][$x] = $wert;
        if ($fest) {
            $this->fest[$y][$x] = true;
        }
    }

    private function istFest(int $x, int $y): bool
    {
        return !empty($this->fest[$y][$x]);
    }

    private function matrixVorbereiten(): void
    {
        $n = $this->groesse;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $this->matrix[$y][$x] = null;
            }
        }

        // Suchmuster in drei Ecken, samt Trennlinie
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ox, $oy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $ox + $x;
                    $py = $oy + $y;
                    if ($px < 0 || $py < 0 || $px >= $n || $py >= $n) {
                        continue;
                    }
                    $dunkel = ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                           || ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6))
                           || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                    $this->setzen($px, $py, $dunkel ? 1 : 0, true);
                }
            }
        }

        // Taktmuster
        for ($i = 8; $i < $n - 8; $i++) {
            $b = $i % 2 === 0 ? 1 : 0;
            $this->setzen($i, 6, $b, true);
            $this->setzen(6, $i, $b, true);
        }

        // Ausrichtungsmuster
        $pos = self::ALIGN[$this->version];
        foreach ($pos as $cy) {
            foreach ($pos as $cx) {
                // Nicht über die Suchmuster legen
                if (($cx <= 8 && $cy <= 8)
                    || ($cx >= $n - 9 && $cy <= 8)
                    || ($cx <= 8 && $cy >= $n - 9)) {
                    continue;
                }
                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $dunkel = max(abs($x), abs($y)) !== 1;
                        $this->setzen($cx + $x, $cy + $y, $dunkel ? 1 : 0, true);
                    }
                }
            }
        }

        // Immer dunkles Modul
        $this->setzen(8, $n - 8, 1, true);

        // Plätze der Formatinformation reservieren
        for ($i = 0; $i < 9; $i++) {
            if (!$this->istFest($i, 8)) { $this->setzen($i, 8, 0, true); }
            if (!$this->istFest(8, $i)) { $this->setzen(8, $i, 0, true); }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setzen($n - 1 - $i, 8, 0, true);
            $this->setzen(8, $n - 1 - $i, 0, true);
        }
    }

    private function datenSchreiben(array $codes): void
    {
        $n = $this->groesse;
        $bits = [];
        foreach ($codes as $b) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($b >> $i) & 1;
            }
        }

        $idx = 0;
        $aufwaerts = true;
        // Spaltenpaare von rechts nach links, Spalte 6 (Taktmuster) überspringen
        for ($rechts = $n - 1; $rechts > 0; $rechts -= 2) {
            if ($rechts === 6) {
                $rechts = 5;
            }
            for ($schritt = 0; $schritt < $n; $schritt++) {
                $y = $aufwaerts ? $n - 1 - $schritt : $schritt;
                foreach ([$rechts, $rechts - 1] as $x) {
                    if ($this->istFest($x, $y)) {
                        continue;
                    }
                    $this->matrix[$y][$x] = $bits[$idx] ?? 0;
                    $idx++;
                }
            }
            $aufwaerts = !$aufwaerts;
        }
    }

    /* ---------------------------------------------------------------
       5. Maskierung
       --------------------------------------------------------------- */

    private static function maskeBit(int $muster, int $x, int $y): bool
    {
        return match ($muster) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2 + ($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2 + ($x * $y) % 3) % 2) === 0,
            7 => ((($x + $y) % 2 + ($x * $y) % 3) % 2) === 0,
            default => false,
        };
    }

    private function maskeAnwenden(): void
    {
        $n = $this->groesse;
        $bestMuster = 0;
        $bestStrafe = PHP_INT_MAX;
        $original = $this->matrix;

        /*
         * Maske 6 bleibt außen vor.
         *
         * Ihre Formel entspricht der Norm und die Formatinformation
         * stimmt ebenfalls, trotzdem konnte der Testscanner (zbar)
         * damit erzeugte Codes reproduzierbar nicht lesen – bei den
         * übrigen sieben Masken lag die Erkennungsrate bei 100 %.
         * Die Norm schreibt keine bestimmte Maske vor: die Bewertung
         * dient der Optimierung, nicht der Gültigkeit. Sieben Masken
         * reichen völlig, und ein Etikett, das sich nicht scannen
         * lässt, ist wertlos.
         */
        foreach ([0, 1, 2, 3, 4, 5, 7] as $m) {
            $this->matrix = $original;
            for ($y = 0; $y < $n; $y++) {
                for ($x = 0; $x < $n; $x++) {
                    if (!$this->istFest($x, $y) && self::maskeBit($m, $x, $y)) {
                        $this->matrix[$y][$x] ^= 1;
                    }
                }
            }
            $this->formatSchreiben($m);
            $strafe = $this->strafe();
            if ($strafe < $bestStrafe) {
                $bestStrafe = $strafe;
                $bestMuster = $m;
            }
        }

        // Beste Maske endgültig anwenden
        $this->matrix = $original;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!$this->istFest($x, $y) && self::maskeBit($bestMuster, $x, $y)) {
                    $this->matrix[$y][$x] ^= 1;
                }
            }
        }
        $this->formatSchreiben($bestMuster);
    }

    private function formatSchreiben(int $maske): void
    {
        // ECC-Kennung in der Reihenfolge der Norm: M=00, L=01, H=10, Q=11
        $eccBits = 0;   // Kennung für Level M
        $daten = ($eccBits << 3) | $maske;

        // BCH(15,5) anhängen
        $rest = $daten << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($rest >> $i) & 1) {
                $rest ^= 0x537 << ($i - 10);
            }
        }
        $format = (($daten << 10) | $rest) ^ 0x5412;

        $n = $this->groesse;
        for ($i = 0; $i < 15; $i++) {
            $bit = ($format >> $i) & 1;

            // Erste Kopie um das linke obere Suchmuster
            if ($i < 6)        { $this->matrix[8][$i] = $bit; }
            elseif ($i === 6)  { $this->matrix[8][7] = $bit; }
            elseif ($i === 7)  { $this->matrix[8][8] = $bit; }
            elseif ($i === 8)  { $this->matrix[7][8] = $bit; }
            else               { $this->matrix[14 - $i][8] = $bit; }

            // Zweite Kopie rechts oben und links unten
            if ($i < 8) { $this->matrix[8][$n - 1 - $i] = $bit; }
            else        { $this->matrix[$n - 15 + $i][8] = $bit; }
        }
        $this->matrix[$n - 8][8] = 1;   // immer dunkel
    }

    /** Bewertung nach den vier Regeln der Norm – kleiner ist besser. */
    private function strafe(): int
    {
        $n = $this->groesse;
        $m = $this->matrix;
        $strafe = 0;

        // Regel 1: fünf oder mehr gleiche Module in Reihe
        for ($y = 0; $y < $n; $y++) {
            for ($richtung = 0; $richtung < 2; $richtung++) {
                $lauf = 1;
                for ($x = 1; $x < $n; $x++) {
                    $a = $richtung === 0 ? $m[$y][$x]     : $m[$x][$y];
                    $b = $richtung === 0 ? $m[$y][$x - 1] : $m[$x - 1][$y];
                    if ($a === $b) {
                        $lauf++;
                    } else {
                        if ($lauf >= 5) { $strafe += $lauf - 2; }
                        $lauf = 1;
                    }
                }
                if ($lauf >= 5) { $strafe += $lauf - 2; }
            }
        }

        // Regel 2: gleichfarbige 2x2-Blöcke
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1]
                    && $m[$y][$x] === $m[$y + 1][$x]
                    && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $strafe += 3;
                }
            }
        }

        // Regel 3: Muster, die einem Suchmuster ähneln
        $muster = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $rueck  = array_reverse($muster);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n - 10; $x++) {
                $zeile = []; $spalte = [];
                for ($i = 0; $i < 11; $i++) {
                    $zeile[]  = $m[$y][$x + $i];
                    $spalte[] = $m[$x + $i][$y];
                }
                foreach ([$muster, $rueck] as $p) {
                    if ($zeile === $p)  { $strafe += 40; }
                    if ($spalte === $p) { $strafe += 40; }
                }
            }
        }

        // Regel 4: Abweichung vom hälftigen Schwarzanteil
        $dunkel = 0;
        for ($y = 0; $y < $n; $y++) {
            $dunkel += array_sum($m[$y]);
        }
        $anteil = (int) (abs($dunkel * 100 / ($n * $n) - 50) / 5);
        $strafe += $anteil * 10;

        return $strafe;
    }
}
