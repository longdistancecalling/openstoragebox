<?php
declare(strict_types=1);

/**
 * Anmeldung und Rechte.
 *
 * Wird von config.php eingebunden und erzwingt auf jeder Seite außer
 * login.php eine gültige Anmeldung. Die Rechteprüfung ist ausschließlich
 * serverseitig – ausgeblendete Buttons sind Bequemlichkeit, keine
 * Sicherheit.
 */

/** Rollen in aufsteigender Rangfolge. Eine höhere Rolle darf alles der niedrigeren. */
const ROLLEN = [
    'leser'      => 1,
    'bearbeiter' => 2,
    'admin'      => 3,
];

const ROLLEN_LABEL = [
    'leser'      => 'Leser',
    'bearbeiter' => 'Bearbeiter',
    'admin'      => 'Administrator',
];

const LOGIN_MAX_FEHLVERSUCHE = 5;
const LOGIN_SPERRE_MINUTEN   = 15;

/** Seiten, die ohne Anmeldung erreichbar sind. */
// darstellung.php setzt nur ein Cookie für Farben und Schrift – auch die
// Anmeldeseite soll sich umstellen lassen.
const OHNE_LOGIN = ['login.php', 'darstellung.php'];

/**
 * Der angemeldete Benutzer aus der Datenbank, oder null. Wird pro Request
 * einmal geladen; $neuLaden verwirft den gemerkten Stand (nach login()).
 */
function benutzer(bool $neuLaden = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$neuLaden) {
        return $cache;
    }

    global $pdo;
    $id = $_SESSION['benutzer_id'] ?? null;
    if (!is_int($id)) {
        return $cache = null;
    }

    // Jedes Mal frisch aus der Datenbank: eine Deaktivierung oder ein
    // Rollenwechsel wirken damit sofort, nicht erst beim nächsten Login.
    $stmt = $pdo->prepare('SELECT benutzer_id, benutzername, anzeigename, rolle FROM benutzer WHERE benutzer_id = ? AND aktiv = 1');
    $stmt->execute([$id]);
    $b = $stmt->fetch() ?: null;

    if ($b === null) {
        unset($_SESSION['benutzer_id']);
    }
    return $cache = $b;
}

function angemeldet(): bool
{
    return benutzer() !== null;
}

/** Hat der Benutzer mindestens diese Rolle? */
function darf(string $rolle): bool
{
    $b = benutzer();
    if ($b === null || !isset(ROLLEN[$rolle])) {
        return false;
    }
    return ROLLEN[$b['rolle']] >= ROLLEN[$rolle];
}

/**
 * Die Startseite: wohin es nach der Anmeldung geht und was die Adresse
 * ohne Seitenangabe zeigt. Das Terminal für alle, die buchen dürfen;
 * Leser landen in der Artikelliste, das Terminal ist ihnen nicht
 * freigegeben.
 */
function startseite(): string
{
    return darf('bearbeiter') ? 'touch.php' : 'index.php';
}

/** Bricht mit 403 ab, wenn die Rolle fehlt. Für POST-Handler und geschützte Seiten. */
function erfordere(string $rolle): void
{
    if (darf($rolle)) {
        return;
    }
    http_response_code(403);
    $titel = 'Kein Zugriff';
    $aktiv = '';
    require __DIR__ . '/header.php';
    echo '<p class="leer">Für diese Aktion ist die Rolle <strong>'
       . h(ROLLEN_LABEL[$rolle]) . '</strong> nötig. '
       . 'Du bist als <strong>' . h(ROLLEN_LABEL[benutzer()['rolle']]) . '</strong> angemeldet.</p>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Leitet nicht angemeldete Besucher zur Login-Seite. Wird von config.php aufgerufen. */
function auth_erzwingen(): void
{
    $seite = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($seite, OHNE_LOGIN, true)) {
        return;
    }
    if (angemeldet()) {
        return;
    }
    // Zielseite merken, damit es nach dem Login dort weitergeht.
    $_SESSION['nach_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
    redirect('login.php');
}

/**
 * Anmeldeversuch. Gibt bei Erfolg null zurück, sonst eine Fehlermeldung.
 *
 * Die Meldung ist absichtlich immer dieselbe – ob der Benutzername
 * nicht existiert oder das Passwort falsch war, verrät sie nicht.
 * Sonst ließe sich abfragen, welche Konten es gibt.
 */
function login(string $benutzername, string $passwort): ?string
{
    global $pdo;
    $allgemein = 'Anmeldung fehlgeschlagen.';

    $stmt = $pdo->prepare('SELECT * FROM benutzer WHERE benutzername = ?');
    $stmt->execute([$benutzername]);
    $b = $stmt->fetch();

    if (!$b) {
        // Trotzdem hashen, damit die Antwortzeit nicht verrät, ob es den
        // Benutzer gibt (Timing-Seitenkanal).
        password_verify($passwort, '$2y$10$abcdefghijklmnopqrstuuAbCdEfGhIjKlMnOpQrStUvWxYz012345');
        return $allgemein;
    }

    if (!$b['aktiv']) {
        return $allgemein;
    }

    if ($b['gesperrt_bis'] !== null && strtotime($b['gesperrt_bis']) > time()) {
        $min = (int) ceil((strtotime($b['gesperrt_bis']) - time()) / 60);
        return "Zu viele Fehlversuche. Konto ist noch $min Minuten gesperrt.";
    }

    if (!password_verify($passwort, $b['passwort_hash'])) {
        $versuche = (int) $b['fehlversuche'] + 1;
        $sperre   = $versuche >= LOGIN_MAX_FEHLVERSUCHE
                  ? date('Y-m-d H:i:s', time() + LOGIN_SPERRE_MINUTEN * 60)
                  : null;
        $pdo->prepare('UPDATE benutzer SET fehlversuche = ?, gesperrt_bis = ? WHERE benutzer_id = ?')
            ->execute([$sperre ? 0 : $versuche, $sperre, $b['benutzer_id']]);
        return $allgemein;
    }

    // Erfolg. Neue Session-ID gegen Session-Fixation: ein Angreifer, der
    // dem Opfer vorher eine Session-ID untergeschoben hat, sitzt sonst
    // nach dessen Login in einer angemeldeten Session.
    session_regenerate_id(true);
    $_SESSION['benutzer_id'] = (int) $b['benutzer_id'];
    // Die Login-Seite hat vorher schon "niemand angemeldet" gemerkt; ohne
    // Auffrischen hielten darf() und startseite() den Benutzer für Gast.
    benutzer(true);

    $pdo->prepare('UPDATE benutzer SET fehlversuche = 0, gesperrt_bis = NULL, letzter_login = NOW() WHERE benutzer_id = ?')
        ->execute([$b['benutzer_id']]);

    // Wenn der Hash-Algorithmus inzwischen stärker eingestellt ist,
    // beim Login still neu hashen.
    if (password_needs_rehash($b['passwort_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE benutzer SET passwort_hash = ? WHERE benutzer_id = ?')
            ->execute([password_hash($passwort, PASSWORD_DEFAULT), $b['benutzer_id']]);
    }

    return null;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Mindestanforderungen an ein Passwort. Gibt Fehlermeldung oder null. */
function passwort_pruefen(string $pw, string $wiederholung): ?string
{
    if (mb_strlen($pw) < 10) {
        return 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    }
    if ($pw !== $wiederholung) {
        return 'Die beiden Passwörter stimmen nicht überein.';
    }
    return null;
}
