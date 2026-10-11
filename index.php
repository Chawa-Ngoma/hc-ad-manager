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

$title = business_name();
$icon = [
    'ad' => '<rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 8h8M8 12h8M8 16h4"/>',
    'send' => '<path d="M3 11l18-8-7 18-3-7z"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= h(csrf()) ?>">
<meta name="theme-color" content="#c4483f">
<title><?= h($title) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="stylesheet" href="assets/app.css">
</head>
<?php if (!authed()): ?>
<body class="auth">
<main class="card">
<h1><?= h($title) ?></h1>
<h2><?= $setup ? 'Choose a password' : 'Sign in' ?></h2>
<?php if ($error !== ''): ?>
<div class="notice" role="alert"><?= h($error) ?></div>
<?php endif; ?>
<form method="post" action="index.php">
<input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
<label class="field">Password
<input type="password" name="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required autofocus>
</label>
<?php if ($setup): ?>
<label class="field">Type the password again
<input type="password" name="password2" autocomplete="new-password" required>
</label>
<?php endif; ?>
<button type="submit" class="btn primary block"><?= $setup ? 'Save password and continue' : 'Sign in' ?></button>
</form>
</main>
</body>
<?php else: ?>
<body>
<div class="app">
<header class="top">
<div class="brand"><?= h($title) ?></div>
<button type="button" class="btn" id="signout">Sign out</button>
</header>

<section class="tab" id="tab-ad" aria-labelledby="t-ad">
<div class="seg" role="group" aria-label="What to show">
<button type="button" id="seg-edit" aria-pressed="true">Edit details</button>
<button type="button" id="seg-see" aria-pressed="false">See the ad</button>
</div>
<div class="cols" id="ad-cols" data-view="edit">
<form class="editor" id="ad-form" novalidate>
<section class="panel">
<h2>When and where</h2>
<label class="field">Venue
<input type="text" name="venue" maxlength="80" placeholder="Where is it happening?" autocomplete="off">
</label>
<div class="pair">
<label class="field">Date
<input type="date" name="date">
</label>
<label class="field">Time
<input type="time" name="time">
</label>
</div>
</section>

<section class="panel">
<h2>What we're selling</h2>
<label class="field">Headline
<input type="text" name="title" maxlength="80" placeholder="Your business name" autocomplete="off">
</label>
<label class="field">Also showing with (optional)
<input type="text" name="partner" maxlength="60" placeholder="Another business joining you" autocomplete="off">
</label>
<label class="field">Short description (optional)
<textarea name="desc" rows="3" maxlength="260" placeholder="Tell customers what you are showing."></textarea>
</label>
<fieldset class="field">
<legend>Items shown</legend>
<div class="pills">
<?php foreach (AD_ITEMS as $key => $label): ?>
<label class="pill"><input type="checkbox" name="items[]" value="<?= h($key) ?>"><span><?= h($label) ?></span></label>
<?php endforeach; ?>
</div>
</fieldset>
</section>

<section class="panel">
<h2>Contact</h2>
<?php foreach ([1, 2, 3] as $n): ?>
<fieldset class="field">
<legend>Contact <?= $n ?> (optional)</legend>
<div class="pair">
<label class="field">Name
<input type="text" name="c<?= $n ?>n" maxlength="30" placeholder="Their name" autocomplete="off">
</label>
<label class="field">Phone
<input type="tel" name="c<?= $n ?>p" maxlength="30" placeholder="Phone number" autocomplete="off">
</label>
</div>
</fieldset>
<?php endforeach; ?>
<label class="field">Payment line (optional)
<input type="text" name="pay" maxlength="80" placeholder="How customers can pay" autocomplete="off">
</label>
</section>

<div class="notice" id="ad-error" role="alert" hidden></div>
<div class="row">
<button type="submit" class="btn primary" id="ad-save">Save changes</button>
<button type="button" class="btn" id="ad-undo">Undo changes</button>
</div>
<p class="state" id="ad-state" role="status"></p>
</form>
<div class="previewcol"><canvas id="flyer" class="flyer" width="1200" height="1800" role="img" aria-label="Preview of the flyer"></canvas></div>
</div>
</section>

<section class="tab" id="tab-send" aria-labelledby="t-send" hidden>
<div class="cols">
<div class="panel"><h2>Send</h2><p class="muted">The email form will go here.</p></div>
<div class="panel"><h2>Email preview</h2><p class="muted">The email preview will go here.</p></div>
</div>
</section>

<section class="tab" id="tab-settings" aria-labelledby="t-settings" hidden>
<div class="panel"><h2>Settings</h2><p class="muted">The settings will go here.</p></div>
</section>
</div>

<nav class="tabbar" role="tablist" aria-label="Sections">
<?php foreach (['ad' => 'Ad', 'send' => 'Send', 'settings' => 'Settings'] as $key => $label): ?>
<button type="button" role="tab" id="t-<?= $key ?>" data-tab="<?= $key ?>" aria-selected="<?= $key === 'ad' ? 'true' : 'false' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon[$key] ?></svg><?= h($label) ?></button>
<?php endforeach; ?>
</nav>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<script>window.APP = <?= json_encode(['csrf' => csrf(), 'ad' => get_ad()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/flyer.js"></script>
<script src="assets/app.js"></script>
</body>
<?php endif; ?>
</html>
