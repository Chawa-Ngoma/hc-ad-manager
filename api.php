<?php
// The one JSON endpoint. POST only; every call needs a session and an X-CSRF header.
require __DIR__ . '/lib.php';
boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_out(['error' => 'Only POST is allowed.'], 405);
}

require_api_auth();

switch ($_POST['action'] ?? '') {
    case 'logout':
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => true]);
    default:
        json_out(['error' => 'Unknown action.'], 400);
}
