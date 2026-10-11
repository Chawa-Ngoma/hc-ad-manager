<?php
// Boot, escaping, SQLite storage and encryption helpers.

const SESSION_LIFETIME = 60 * 60 * 24 * 30; // 30 days

if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}
if (!defined('APP_DATA_DIR')) {
    define('APP_DATA_DIR', __DIR__ . '/data');
}

function boot(): void
{
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

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
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

// The name shown in the header and on the sign-in card. Settings will supply it later.
function business_name(): string
{
    return 'Ad Manager';
}
