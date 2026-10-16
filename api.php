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
    case 'save_ad':
        $in = json_decode((string) ($_POST['data'] ?? ''), true);
        if (!is_array($in)) {
            json_out(['error' => 'The ad could not be read. Please try again.'], 422);
        }
        if (clean_text($in['venue'] ?? '', AD_LIMITS['venue']) === '') {
            json_out(['error' => 'Please enter a venue.'], 422);
        }
        if (clean_text($in['title'] ?? '', AD_LIMITS['title']) === '') {
            json_out(['error' => 'Please enter a headline.'], 422);
        }
        $ad = clean_ad($in);
        kset('ad', $ad);
        json_out(['ad' => $ad]);
    case 'save_settings':
        $in = json_decode((string) ($_POST['data'] ?? ''), true);
        if (!is_array($in)) {
            json_out(['error' => 'The settings could not be read. Please try again.'], 422);
        }
        $newPassword = $in['newPassword'] ?? '';
        if (!is_string($newPassword)) {
            $newPassword = '';
        }
        try {
            $settings = clean_settings($in, get_settings());
        } catch (InvalidArgumentException $e) {
            json_out(['error' => $e->getMessage()], 422);
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            json_out(['error' => 'The app password must be at least 8 characters.'], 422);
        }
        // Everything is valid: store the settings and the new password together, or neither.
        db()->beginTransaction();
        kset('settings', $settings);
        if ($newPassword !== '') {
            kset('pass_hash', password_hash($newPassword, PASSWORD_DEFAULT));
        }
        db()->commit();
        if ($newPassword !== '') {
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        json_out(['settings' => public_settings(), 'csrf' => csrf()]);
    default:
        json_out(['error' => 'Unknown action.'], 400);
}
