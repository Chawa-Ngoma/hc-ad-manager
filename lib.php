<?php
// Boot, escaping, SQLite storage and encryption helpers.

const SESSION_LIFETIME = 60 * 60 * 24 * 30; // 30 days

if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}
if (!defined('APP_DATA_DIR')) {
    define('APP_DATA_DIR', __DIR__ . '/data');
}

// Anything that was not handled on purpose ends here: one short line in the PHP error log (never the exception's
// message, which can hold paths or SQL) and a plain sentence for the owner instead of a stack trace.
function unexpected_error(Throwable $e): void
{
    error_log('Ad Manager: unexpected error (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').');
    $text = 'Something went wrong on the server. Please try again. If it keeps happening, ask whoever looks after the server to check the PHP error log.';
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $text]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
    }
}

function boot(): void
{
    set_exception_handler('unexpected_error');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    date_default_timezone_set('Africa/Johannesburg');

    foreach (['pdo_sqlite', 'openssl'] as $ext) {
        if (!extension_loaded($ext)) {
            http_response_code(500);
            exit("This app needs the PHP extension \"$ext\", which is not turned on on this server.");
        }
    }

    foreach ([APP_DATA_DIR, APP_DATA_DIR . '/sessions', APP_DATA_DIR . '/outbox'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            http_response_code(500);
            exit('The app cannot create its data folder. Please check the folder permissions.');
        }
    }

    $htaccess = APP_DATA_DIR . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        // gc_maxlifetime must match the cookie, or PHP deletes session files early.
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        ini_set('session.use_strict_mode', '1');
        session_save_path(APP_DATA_DIR . '/sessions');
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // Create the database and key on the first request.
    db();
    secret();
}

function authed(): bool
{
    return !empty($_SESSION['authed']);
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok($token): bool
{
    return is_string($token) && hash_equals(csrf(), $token);
}

// Marks the session as signed in, with a fresh session id and CSRF token.
function sign_in(): void
{
    session_regenerate_id(true);
    $_SESSION['authed'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function require_api_auth(): void
{
    if (!authed()) {
        json_out(['error' => 'Please sign in again.'], 401);
    }
    if (!csrf_ok($_SERVER['HTTP_X_CSRF'] ?? '')) {
        json_out(['error' => 'Your page has expired. Please reload it and try again.'], 403);
    }
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . APP_DATA_DIR . '/app.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            at TEXT NOT NULL,
            subject TEXT NOT NULL,
            total INTEGER NOT NULL,
            sent INTEGER NOT NULL,
            failed INTEGER NOT NULL
        )');
    }
    return $pdo;
}

function kget(string $key, $default = null)
{
    $st = db()->prepare('SELECT v FROM kv WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}

function kset(string $key, $value): void
{
    $st = db()->prepare('INSERT INTO kv (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $st->execute([$key, json_encode($value)]);
}

// 32 random bytes, stored as hex in data/secret.key. Returns the raw bytes.
function secret(): string
{
    $file = APP_DATA_DIR . '/secret.key';
    if (!is_file($file)) {
        file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        chmod($file, 0600);
    }
    return hex2bin(trim((string) file_get_contents($file)));
}

// AES-256-GCM. Blob = base64(iv 12 bytes + tag 16 bytes + ciphertext).
function enc(string $text): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($text, 'aes-256-gcm', secret(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $cipher);
}

// Returns an empty string for anything that is not a valid blob.
function dec(string $blob): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

// The name shown in the signed-in header: the saved sender name, or "Ad Manager" until one is saved.
function business_name(): string
{
    $name = get_settings()['senderName'];
    return $name !== '' ? $name : 'Ad Manager';
}

// Mail settings. Empty sender email or server means "not filled in yet"; sending is blocked until they are.
const SET_DEFAULT = [
    'senderName' => '', 'senderEmail' => '', 'replyTo' => '', 'ccEmail' => '',
    'smtpHost' => '', 'smtpPort' => 465, 'smtpSecurity' => 'SSL/TLS',
    'smtpUser' => '', 'smtpPassEnc' => '', 'trustCert' => false, 'batchLimit' => 50,
];

// The saved settings; any missing or wrongly typed value falls back to its default.
function get_settings(): array
{
    $stored = kget('settings');
    $out = SET_DEFAULT;
    if (is_array($stored)) {
        foreach (SET_DEFAULT as $key => $default) {
            if (isset($stored[$key]) && gettype($stored[$key]) === gettype($default)) {
                $out[$key] = $stored[$key];
            }
        }
    }
    return $out;
}

// What the browser may see: everything except the encrypted password, plus whether one is saved.
function public_settings(): array
{
    $s = get_settings();
    $s['hasPass'] = $s['smtpPassEnc'] !== '';
    unset($s['smtpPassEnc']);
    return $s;
}

// Trims and collapses whitespace like clean_text, but rejects a value that is too long instead of cutting it.
function set_text(array $in, array $base, string $key, int $max, string $label): string
{
    $v = array_key_exists($key, $in) ? clean_text($in[$key], 10000) : $base[$key];
    if (!preg_match('/^.{0,' . $max . '}$/su', $v)) {
        throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
    }
    return $v;
}

function set_int($v, int $min, int $max, string $label): int
{
    $v = is_string($v) ? trim($v) : $v;
    if (is_string($v) && ctype_digit($v)) {
        $v = (int) $v;
    }
    if (!is_int($v) || $v < $min || $v > $max) {
        throw new InvalidArgumentException($label . ' must be a number from ' . $min . ' to ' . $max . '.');
    }
    return $v;
}

// Checks every value and returns the new settings, or throws InvalidArgumentException with a message naming
// the field. Nothing is stored here. smtpPassEnc is never read from $in: a non-empty smtpPass is encrypted,
// an empty one keeps the saved value from $base.
function clean_settings(array $in, array $base): array
{
    $out = $base;
    $out['senderName'] = set_text($in, $base, 'senderName', 60, 'Sender name');
    foreach (['senderEmail' => 'Sender email', 'replyTo' => 'Replies go to', 'ccEmail' => 'Always CC me'] as $key => $label) {
        $out[$key] = set_text($in, $base, $key, 254, $label);
        if ($out[$key] !== '' && !filter_var($out[$key], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException($label . ' is not a valid address.');
        }
    }
    $out['smtpHost'] = set_text($in, $base, 'smtpHost', 253, 'Server name');
    if ($out['smtpHost'] !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $out['smtpHost'])) {
        throw new InvalidArgumentException('Server name can only have letters, digits, dots and hyphens.');
    }
    $out['smtpPort'] = set_int($in['smtpPort'] ?? $base['smtpPort'], 1, 65535, 'Port');
    $out['smtpSecurity'] = $in['smtpSecurity'] ?? $base['smtpSecurity'];
    if (!in_array($out['smtpSecurity'], ['SSL/TLS', 'STARTTLS', 'None'], true)) {
        throw new InvalidArgumentException('Security must be SSL/TLS, STARTTLS or None.');
    }
    $out['smtpUser'] = set_text($in, $base, 'smtpUser', 100, 'Username');
    $out['trustCert'] = $in['trustCert'] ?? $base['trustCert'];
    if (!is_bool($out['trustCert'])) {
        throw new InvalidArgumentException('Trust the server certificate must be on or off.');
    }
    $out['batchLimit'] = set_int($in['batchLimit'] ?? $base['batchLimit'], 1, 200, 'Most emails at one time');
    $pass = $in['smtpPass'] ?? '';
    if (is_string($pass) && $pass !== '') {
        if (strlen($pass) > 255) {
            throw new InvalidArgumentException('Password must be 255 characters or fewer.');
        }
        $out['smtpPassEnc'] = enc($pass);
    }
    return $out;
}

// Items the ad can show, in display order: key => label.
const AD_ITEMS = [
    'shoes' => 'Shoes',
    'bags' => 'Handbags',
    'jewel' => 'Jewellery',
    'cloth' => 'Clothing',
    'card' => 'Cardigans',
    'blouse' => 'Blouses',
];

// Longest allowed length of each text field.
const AD_LIMITS = [
    'venue' => 80, 'title' => 80, 'partner' => 60, 'desc' => 260,
    'c1n' => 30, 'c1p' => 30, 'c2n' => 30, 'c2p' => 30, 'c3n' => 30, 'c3p' => 30,
    'pay' => 80,
];

// Key order here is the order of every cleaned ad.
function ad_default(): array
{
    return [
        'venue' => 'Your venue',
        'date' => (new DateTime('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d'),
        'time' => '09:00',
        'title' => 'Your business name',
        'partner' => '',
        'desc' => 'Tell customers what you are showing.',
        'items' => array_keys(AD_ITEMS),
        'c1n' => 'Your name',
        'c1p' => '000 000 0000',
        'c2n' => '', 'c2p' => '',
        'c3n' => '', 'c3p' => '',
        'pay' => 'All major cards are accepted',
    ];
}

// Collapses whitespace, trims and cuts to $max characters. Anything that is
// not valid UTF-8 or not a string counts as empty.
function clean_text($value, int $max): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\s\p{Cc}]+/u', ' ', $value); // null when the text is not valid UTF-8
    if ($value === null) {
        return '';
    }
    preg_match('/^.{0,' . $max . '}/su', trim($value), $m);
    return rtrim($m[0] ?? '');
}

// Never fails. Missing keys take the default; bad or empty required values fall back to it.
function clean_ad(array $in): array
{
    $out = [];
    foreach (ad_default() as $key => $default) {
        $has = array_key_exists($key, $in);
        if ($key === 'date') {
            $v = $has ? clean_text($in[$key], 40) : '';
            $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
            $out[$key] = $ok ? $v : $default;
        } elseif ($key === 'time') {
            $v = $has ? clean_text($in[$key], 40) : '';
            $out[$key] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $default;
        } elseif ($key === 'items') {
            $chosen = !$has ? $default : (is_array($in[$key]) ? $in[$key] : []);
            $out[$key] = array_values(array_filter(array_keys(AD_ITEMS), fn($k) => in_array($k, $chosen, true)));
        } else {
            $v = $has ? clean_text($in[$key], AD_LIMITS[$key]) : $default;
            $out[$key] = ($v === '' && ($key === 'venue' || $key === 'title')) ? $default : $v;
        }
    }
    return $out;
}

// The current ad. The first call stores the defaults so the seeded date stays fixed.
function get_ad(): array
{
    $stored = kget('ad');
    if (!is_array($stored)) {
        $ad = clean_ad([]);
        kset('ad', $ad);
        return $ad;
    }
    return clean_ad($stored);
}

// The 10 most recent finished sends, newest first. The table holds counts only, never an address.
function recent_sends(): array
{
    return db()->query('SELECT at, subject, total, sent, failed FROM sends ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
}
