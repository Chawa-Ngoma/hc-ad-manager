<?php
// First-run password, sign-in, and (for now) a signed-in placeholder.
require __DIR__ . '/lib.php';
boot();

// "assets/app.css?v=1a2b3c4d": the version is a short hash of the file's content, so a phone fetches a file again only
// when it really changed (a file time can change without the content, or stay the same after an upload).
function asset_url(string $path): string
{
    $hash = @md5_file(__DIR__ . '/' . $path);
    return $path . ($hash === false ? '' : '?v=' . substr($hash, 0, 8));
}

const MAX_FAILS = 5;
const LOCK_SECONDS = 60;
const RESET_FLAG = 'reset-password.txt';
const RESET_MINUTES = 60;

// Reset mode: the owner made the file data/reset-password.txt (File Manager). It counts for 60 minutes from its file
// time. A file that is older, or dated in the future, is ignored and removed.
function reset_on(): bool
{
    $file = APP_DATA_DIR . '/' . RESET_FLAG;
    clearstatcache(true, $file);
    if (!is_file($file)) {
        return false;
    }
    $age = time() - (int) filemtime($file);
    if ($age >= 0 && $age <= RESET_MINUTES * 60) {
        return true;
    }
    @unlink($file);
    return false;
}

// A reset ends every old sign-in and clears the lockout.
function reset_finish(): void
{
    foreach (glob(APP_DATA_DIR . '/sessions/sess_*') ?: [] as $session) {
        @unlink($session);
    }
    kset('fails', ['count' => 0, 'until' => 0]);
}

// Returns an error message, or '' when the user is now signed in.
function handle_post(): string
{
    if (!csrf_ok($_POST['csrf'] ?? '')) {
        return 'Your page has expired. Please try again.';
    }
    $password = (string) ($_POST['password'] ?? '');

    // A password can be chosen on the first run (none exists yet) or while reset mode is on, and at no other time.
    $reset = reset_on();
    if ($reset || kget('pass_hash') === null) {
        if (strlen($password) < 8) {
            return 'Your password must be at least 8 characters.';
        }
        if (!hash_equals($password, (string) ($_POST['password2'] ?? ''))) {
            return 'The two passwords do not match.';
        }
        if ($reset) {
            // Taking the flag away is what claims the reset: of two requests, only one can succeed.
            if (!@unlink(APP_DATA_DIR . '/' . RESET_FLAG)) {
                return 'Reset mode has ended. Please try again.';
            }
            reset_finish();
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

// The app is always reached as "./" (the folder), so index.php stays out of the address bar. A folder opened without its
// closing slash (Apache already adds it; the built-in PHP server does not) would make "./" mean the folder above,
// so such a visit is sent to the same address with the slash. /index.php itself still works.
$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true) && !str_ends_with($uri, '/') && !str_ends_with($uri, '.php')) {
    // Only the last part of the path is used, as a relative address ("./name/"): it can never name another host,
    // whatever the request line or the Host header says, and the "./" keeps a name such as "a:b" from reading as a scheme.
    $last = substr($uri, (int) strrpos($uri, '/') + 1);
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ./' . $last . '/' . ($query === '' ? '' : '?' . $query), true, 301);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !authed()) {
    $error = handle_post();
    if ($error === '') {
        header('Location: ./');
        exit;
    }
}

$reset = reset_on();
$setup = $reset || kget('pass_hash') === null;

// The owner is told when the site is not on HTTPS (this machine, for testing, is exempt).
$plain = (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') && !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
$plainNote = 'This page is not using HTTPS, so your password and emails are not protected on their way. Ask whoever looks after the server to turn HTTPS on.';

$title = 'Ad Manager'; // the sign-in card and the page title never read the settings
$blurb = 'Edit your flyer and email it to your customers.'; // the text a chat app shows under a pasted link
// The preview picture needs a full address, so it appears only when config.php sets APP_URL (http:// or https://).
$shareImage = defined('APP_URL') && preg_match('~^https?://[^\s<>"\']+$~i', (string) APP_URL)
    ? rtrim((string) APP_URL, '/') . '/assets/icon-512.png' : '';
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
<meta name="description" content="<?= h($blurb) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($blurb) ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($blurb) ?>">
<?php if ($shareImage !== ''): ?>
<meta property="og:image" content="<?= h($shareImage) ?>">
<meta name="twitter:image" content="<?= h($shareImage) ?>">
<?php endif; ?>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="stylesheet" href="<?= h(asset_url('assets/app.css')) ?>">
</head>
<?php if (!authed()): ?>
<body class="auth">
<main class="card">
<h1><?= h($title) ?></h1>
<h2><?= $setup ? 'Choose a password' : 'Sign in' ?></h2>
<?php if ($error !== ''): ?>
<div class="notice" role="alert"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($reset): ?>
<div class="notice" id="reset-note" role="status">Reset mode is on. Choose a new password. To cancel, delete the file reset-password.txt from the data folder.</div>
<?php endif; ?>
<?php if ($plain): ?>
<div class="notice" id="https-note"><?= h($plainNote) ?></div>
<?php endif; ?>
<form method="post" action="./">
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
<div class="brand"><?= h(business_name()) ?></div>
<button type="button" class="btn" id="signout">Sign out</button>
</header>
<?php if ($plain): ?>
<div class="notice" id="https-note"><?= h($plainNote) ?></div>
<?php endif; ?>

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
<div class="previewcol"><canvas id="flyer" class="flyer" width="1200" height="1800" role="img" aria-label="Preview of the flyer"></canvas><div class="row"><button type="button" class="btn primary" id="save-flyer">Save flyer picture</button><button type="button" class="btn" id="save-flyer-pdf">Save flyer as PDF</button></div></div>
</div>
</section>

<section class="tab" id="tab-send" aria-labelledby="t-send" hidden>
<div class="needs" id="send-notice" role="status" hidden>
<p id="send-notice-text"></p>
<button type="button" class="btn" id="go-settings">Go to Settings</button>
</div>
<div class="cols">
<div class="editor">
<div class="panel">
<h2>Send to customers</h2>
<div class="fromline"><span class="hint">From</span> <strong id="send-from"></strong></div>
<label class="field">To
<textarea id="send-to" rows="4" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Paste or type email addresses, separated by commas, spaces or new lines"></textarea>
</label>
<div class="chips" id="send-chips"></div>
<p class="count" id="send-count" role="status"></p>
<label class="field">CC <span class="hint">Optional. Use this to get a copy yourself.</span>
<input type="text" id="send-cc" inputmode="email" maxlength="254" autocomplete="off" autocapitalize="none" spellcheck="false">
</label>
<label class="field">Subject
<input type="text" id="send-subject" maxlength="300" autocomplete="off">
</label>
<label class="field">Message
<textarea id="send-message" rows="7" maxlength="5000" aria-describedby="send-message-count"></textarea>
</label>
<p class="hint" id="send-message-count" hidden></p>
<label class="check"><input type="checkbox" id="send-details" checked><span>Show venue, time and date in the email</span></label>
<label class="check"><input type="checkbox" id="send-image" checked><span>Attach the flyer picture</span></label>
<label class="check"><input type="checkbox" id="send-pdf"><span>Attach the flyer as a PDF</span></label>
</div>
</div>
<div class="sendcol">
<h2>What customers will see</h2>
<iframe id="send-preview" class="mailframe" title="What customers will see" sandbox="allow-same-origin" tabindex="-1"></iframe>
<p class="hint" id="send-preview-note" role="alert" hidden></p>
<button type="button" class="btn primary block" id="send-review">Review and send</button>
<p class="hint" id="send-hint" role="status" hidden></p>
<div class="panel" id="send-summary" hidden>
<h2>Ready to send?</h2>
<dl class="kv">
<dt>From</dt><dd id="sum-from"></dd>
<dt>To</dt><dd id="sum-count"></dd>
<dt>CC</dt><dd id="sum-cc"></dd>
<dt>Subject</dt><dd id="sum-subject"></dd>
<dt>Picture</dt><dd id="sum-image"></dd>
<dt>PDF</dt><dd id="sum-pdf"></dd>
</dl>
<div class="row">
<button type="button" class="btn" id="send-back">Go back</button>
<button type="button" class="btn primary" id="send-now">Send now</button>
</div>
</div>
<div class="panel" id="send-progress" hidden>
<h2>Sending</h2>
<div class="bar" id="send-bar" role="progressbar" aria-label="Sending progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="bar-fill" id="send-bar-fill"></div></div>
<p id="send-progress-text" role="status"></p>
<p class="hint">Please keep this page open until it says it is finished.</p>
</div>
<div class="panel problems" id="send-problem" role="alert" hidden>
<h2>Sending stopped</h2>
<p id="send-problem-text"></p>
<div class="row">
<button type="button" class="btn primary" id="send-retry">Try again</button>
<button type="button" class="btn" id="send-signin">Sign in again</button>
<button type="button" class="btn" id="send-stop">Back</button>
</div>
</div>
<div class="panel" id="send-result" hidden>
<h2 id="send-result-title"></h2>
<p id="send-result-counts"></p>
<div id="send-failed" hidden><h3>These did not go out</h3><ul class="plainlist" id="send-failed-list"></ul></div>
<div id="send-unconfirmed" hidden><h3>These may or may not have received it</h3><ul class="plainlist" id="send-unconfirmed-list"></ul></div>
<p id="send-cc-note" hidden></p>
<button type="button" class="btn primary block" id="send-again">Start another</button>
</div>
</div>
</div>
<div class="panel" id="send-log">
<h2>Recent sends</h2>
<p class="muted" id="send-log-empty">Nothing has been sent yet.</p>
<ul class="sendlog" id="send-log-list"></ul>
</div>
</section>

<section class="tab" id="tab-settings" aria-labelledby="t-settings" hidden>
<form class="editor settings" id="settings-form" novalidate>
<div class="panel">
<h2>Email settings</h2>
<label class="field">Sender name
<input type="text" name="senderName" maxlength="60" autocomplete="off" placeholder="The name customers see">
</label>
<label class="field">Sender email
<input type="email" name="senderEmail" maxlength="254" autocomplete="off" placeholder="ads@example.com">
</label>
<label class="field">Replies go to (optional)
<input type="email" name="replyTo" maxlength="254" autocomplete="off" placeholder="Leave empty to use the sender email">
</label>
<label class="field">Always CC me (optional)
<input type="email" name="ccEmail" maxlength="254" autocomplete="off" placeholder="An address that gets a copy of every email">
</label>
</div>
<div class="panel">
<h2>Mail server (SMTP)</h2>
<p class="muted">Use the details of the email account you created in your hosting control panel.</p>
<label class="field">Server name
<input type="text" name="smtpHost" maxlength="253" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="mail.example.com">
</label>
<div class="pair">
<label class="field">Port
<input type="number" name="smtpPort" min="1" max="65535" inputmode="numeric" autocomplete="off">
</label>
<label class="field">Security
<select name="smtpSecurity">
<option value="SSL/TLS">SSL/TLS</option>
<option value="STARTTLS">STARTTLS</option>
<option value="None">None</option>
</select>
</label>
</div>
<p class="hint">"None" sends the password without encryption.</p>
<label class="field">Username
<input type="text" name="smtpUser" maxlength="100" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="ads@example.com">
</label>
<label class="field">Password
<input type="password" name="smtpPass" autocomplete="new-password">
<span class="hint" id="pass-saved" hidden>A password is saved. Leave this empty to keep it.</span>
</label>
<label class="check"><input type="checkbox" name="trustCert"><span>Trust the server certificate</span></label>
<p class="hint">Use this only when a test fails with a certificate error. It turns off the certificate name check and accepts a self-signed certificate, for sending email only.</p>
<label class="field">Send a test to
<input type="email" name="testTo" maxlength="254" autocomplete="off" placeholder="ads@example.com">
</label>
<button type="button" class="btn block" id="test-send">Send test email</button>
<div class="result" id="test-result" role="status" hidden></div>
</div>
<div class="panel">
<h2>Limits and password</h2>
<label class="field">Most emails at one time (1 to 200)
<input type="number" name="batchLimit" min="1" max="200" inputmode="numeric" autocomplete="off">
</label>
<label class="field">New app password (optional)
<input type="password" name="newPassword" autocomplete="new-password">
<span class="hint">At least 8 characters. Leave this empty to keep your current password.</span>
</label>
</div>
<div class="notice" id="settings-error" role="alert" hidden></div>
<button type="submit" class="btn primary block" id="settings-save">Save settings</button>
</form>
</section>
</div>

<nav class="tabbar" role="tablist" aria-label="Sections">
<?php foreach (['ad' => 'Ad', 'send' => 'Send', 'settings' => 'Settings'] as $key => $label): ?>
<button type="button" role="tab" id="t-<?= $key ?>" data-tab="<?= $key ?>" aria-selected="<?= $key === 'ad' ? 'true' : 'false' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon[$key] ?></svg><?= h($label) ?></button>
<?php endforeach; ?>
</nav>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<script>window.APP = <?= json_encode(['csrf' => csrf(), 'ad' => get_ad(), 'settings' => public_settings(), 'log' => recent_sends()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php foreach (['flyer', 'app', 'settings', 'send'] as $js): ?>
<script src="<?= h(asset_url("assets/$js.js")) ?>"></script>
<?php endforeach; ?>
</body>
<?php endif; ?>
</html>
