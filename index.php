<?php
// First-run password, sign-in, and (for now) a signed-in placeholder.
require __DIR__ . '/lib.php';
boot();

const MAX_FAILS = 5;
const LOCK_SECONDS = 60;

// Returns an error message, or '' when the user is now signed in.
function handle_post(): string
{
    if (!csrf_ok($_POST['csrf'] ?? '')) {
        return 'Your page has expired. Please try again.';
    }
    $password = (string) ($_POST['password'] ?? '');

    // First-run setup is only possible while no password exists.
    if (kget('pass_hash') === null) {
        if (strlen($password) < 8) {
            return 'Your password must be at least 8 characters.';
        }
        if (!hash_equals($password, (string) ($_POST['password2'] ?? ''))) {
            return 'The two passwords do not match.';
        }
        kset('pass_hash', password_hash($password, PASSWORD_DEFAULT));
        sign_in();
        return '';
    }

    $fails = kget('fails', ['count' => 0, 'until' => 0]);
    $now = time();
    if ($fails['until'] > $now) {
        return 'Too many wrong tries. Please wait ' . ($fails['until'] - $now) . ' seconds and try again.';
    }
    if ($fails['until'] > 0) {
        $fails = ['count' => 0, 'until' => 0]; // the lock has expired: start a fresh run
    }

    if (password_verify($password, (string) kget('pass_hash'))) {
        kset('fails', ['count' => 0, 'until' => 0]);
        sign_in();
        return '';
    }

    $fails['count']++;
    if ($fails['count'] >= MAX_FAILS) {
        $fails['until'] = $now + LOCK_SECONDS;
    }
    kset('fails', $fails);
    return 'That password is not right.';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !authed()) {
    $error = handle_post();
    if ($error === '') {
        header('Location: index.php');
        exit;
    }
}

$setup = kget('pass_hash') === null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h(csrf()) ?>">
<title>Ad Manager</title>
<style>
:root { color-scheme: light dark; --bg: #f4f4f6; --card: #fff; --text: #1c1c1e; --line: #c9c9d1; --accent: #1a56c4; --err-bg: #fdecec; --err: #9b1c1c; }
@media (prefers-color-scheme: dark) { :root { --bg: #121214; --card: #1e1e22; --text: #f0f0f3; --line: #44444c; --accent: #6ea0ff; --err-bg: #3a1a1a; --err: #ffb4b4; } }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font: 16px/1.4 system-ui, sans-serif; }
.card { width: 100%; max-width: 380px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
h1 { margin: 0 0 4px; font-size: 22px; }
h2 { margin: 0 0 16px; font-size: 18px; font-weight: 600; }
.notice { border: 1px solid var(--err); background: var(--err-bg); color: var(--err); border-radius: 8px; padding: 10px 12px; margin-bottom: 16px; }
label { display: block; margin-bottom: 12px; font-weight: 600; }
input[type=password] { display: block; width: 100%; margin-top: 4px; padding: 12px; font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
button { width: 100%; padding: 12px; font: inherit; font-weight: 600; color: #fff; background: var(--accent); border: 0; border-radius: 8px; cursor: pointer; }
</style>
</head>
<body>
<main class="card">
<h1>Ad Manager</h1>
<?php if (authed()): ?>
<p>You are signed in.</p>
<?php else: ?>
<h2><?= $setup ? 'Choose a password' : 'Sign in' ?></h2>
<?php if ($error !== ''): ?>
<div class="notice" role="alert"><?= h($error) ?></div>
<?php endif; ?>
<form method="post" action="index.php">
<input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
<label>Password
<input type="password" name="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required autofocus>
</label>
<?php if ($setup): ?>
<label>Type the password again
<input type="password" name="password2" autocomplete="new-password" required>
</label>
<?php endif; ?>
<button type="submit"><?= $setup ? 'Save password and continue' : 'Sign in' ?></button>
</form>
<?php endif; ?>
</main>
</body>
</html>
