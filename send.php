<?php
// Batch sending. send_prepare() stores one send as a state file (plus the flyer picture) in data/outbox;
// send_chunk() sends the next few messages and saves its progress around every single recipient.
// Recipient addresses live only in that state file until the send finishes or 24 hours pass. They are never
// logged, and a response carries them only in the list of problems for that send.
// Both functions return [HTTP status, response array]. Needs lib.php.

require_once __DIR__ . '/email.php';
require_once __DIR__ . '/mailer.php';

const SEND_CHUNK = 8;            // recipients per request
const SEND_BUDGET = 15;          // seconds; after this a chunk starts no new recipient, saves, and answers done:false
const SEND_STEP_TIMEOUT = 10;    // seconds allowed for connecting
const SEND_REPLY_WAIT = 4;       // seconds to wait for each reply of the mail server (twice that after the message body, plus one more for the RSET that follows a failure: 12 in all)
const SEND_HUNG = 9;             // a recipient that failed after waiting this long is "unconfirmed" and stops the chunk
const SEND_SLOW = 12;            // a recipient that took longer than this ends the chunk, even when it went out
const SEND_PAUSE_US = 150000;    // pause between messages
const SEND_PNG_MAX = 6291456;    // 6 MB
const SEND_KEEP = 86400;         // seconds a state file may live
const SEND_COPY_MAX = 5;         // addresses that may get "Your copy"

function send_valid_id($id): bool
{
    return is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id) === 1;
}

function send_path(string $id, string $ext): string
{
    return APP_DATA_DIR . '/outbox/' . $id . '.' . $ext;
}

function send_load(string $id): ?array
{
    $raw = @file_get_contents(send_path($id, 'json'));
    $state = $raw === false ? null : json_decode($raw, true);
    return is_array($state) ? $state : null;
}

// Written to a temporary file and renamed, so a request that dies mid-write never leaves half a state file.
function send_save(string $id, array $state): void
{
    $tmp = send_path($id, 'tmp');
    file_put_contents($tmp, json_encode($state), LOCK_EX);
    rename($tmp, send_path($id, 'json'));
}

function send_clear(string $id): void
{
    foreach (['json', 'png', 'tmp'] as $ext) {
        @unlink(send_path($id, $ext));
    }
}

// Everything in the outbox that is older than 24 hours goes.
function send_purge(): void
{
    foreach (glob(APP_DATA_DIR . '/outbox/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - SEND_KEEP) {
            @unlink($file);
        }
    }
}

function send_cut(string $text, int $max): string
{
    preg_match('/^.{0,' . $max . '}/su', $text, $m);
    return $m[0] ?? '';
}

// The uploaded flyer as [error message, bytes].
function send_read_png(?array $file): array
{
    if ($file === null) {
        return ['The flyer picture did not arrive. Please try again, or turn off "Attach the flyer picture".', ''];
    }
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return ['The flyer picture is bigger than this server allows (PHP refused the upload). Send without the picture, or ask whoever looks after the server to raise its upload limit.', ''];
    }
    if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['The flyer picture could not be uploaded. Please try again.', ''];
    }
    if (filesize($file['tmp_name']) > SEND_PNG_MAX) {
        return ['The flyer picture is too big. The limit is 6 MB.', ''];
    }
    $bytes = file_get_contents($file['tmp_name']);
    if ($bytes === false || strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) !== 0) {
        return ['That file is not a PNG picture.', ''];
    }
    return ['', $bytes];
}

function send_prepare(array $in, ?array $file): array
{
    send_purge();
    $s = get_settings();
    if (!smtp_ready($s)) {
        return [422, ['error' => 'Please fill in your email settings first.']];
    }
    $rawTo = $in['to'] ?? [];
    $rawCc = $in['cc'] ?? [];
    if (!is_array($rawTo) || !is_array($rawCc) || count($rawTo) > 1000 || count($rawCc) > 50) {
        return [422, ['error' => 'The list of addresses could not be read. Please try again.']];
    }
    $to = parse_emails($rawTo);
    if (!$to) {
        return [422, ['error' => 'Please add at least one valid email address.']];
    }
    if (count($to) > $s['batchLimit']) {
        return [422, ['error' => 'You can send to ' . $s['batchLimit'] . ' people at a time. Change this limit in Settings.']];
    }
    $cc = parse_emails($rawCc);
    if ($rawCc && !$cc) {
        return [422, ['error' => 'The CC address is not valid.']];
    }
    if (count($cc) > SEND_COPY_MAX) {
        return [422, ['error' => 'You can copy at most ' . SEND_COPY_MAX . ' addresses.']];
    }
    $subject = clean_text($in['subject'] ?? '', 300);
    if ($subject === '') {
        return [422, ['error' => 'Please write a subject.']];
    }
    $image = ($in['image'] ?? true) !== false;
    $png = '';
    if ($image) {
        [$error, $png] = send_read_png($file);
        if ($error !== '') {
            return [422, ['error' => $error]];
        }
    }

    $id = bin2hex(random_bytes(8));
    if ($image && file_put_contents(send_path($id, 'png'), $png) === false) {
        return [500, ['error' => 'The server could not store the flyer picture. Please try again.']];
    }
    send_save($id, [
        'ad' => get_ad(), // the saved copy, never what the browser says
        'subject' => $subject,
        'msg' => send_cut(is_string($in['msg'] ?? '') ? $in['msg'] : '', EMAIL_MESSAGE_MAX),
        'details' => ($in['details'] ?? true) !== false,
        'image' => $image,
        'to' => $to,
        'cc' => $cc,
        'next' => 0,
        'sent' => 0,
        'failed' => [],
        'unconfirmed' => [],
        'inflight' => null,
        'cc_state' => null,
        'created' => time(),
    ]);
    return [200, ['id' => $id, 'total' => count($to), 'image' => $image, 'ignored' => count($rawTo) - count($to)]];
}

function send_view(array $state): array
{
    return [
        'total' => count($state['to']), 'next' => $state['next'], 'sent' => $state['sent'],
        'failed' => $state['failed'], 'unconfirmed' => $state['unconfirmed'],
    ];
}

// Opens the SMTP connection for a chunk. Returns [mailer, ''] or [null, plain-language reason].
function send_connect(array $s): array
{
    try {
        $m = mail_open($s);
        $m->Timeout = SEND_STEP_TIMEOUT;
        // PHPMailer waits for replies with its own, much longer limit (300 s); without this a mail server that stops
        // answering mid-message would hold the request until PHP kills it.
        $m->getSMTPInstance()->Timelimit = SEND_REPLY_WAIT;
        if ($m->smtpConnect() !== true) {
            return [null, 'Could not reach the mail server. Check the server name, port and security setting.'];
        }
        return [$m, ''];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $detail = $s['smtpSecurity'] === 'SSL/TLS' ? mail_tls_reason($s) : '';
        return [null, mail_explain($e, $detail, $s['smtpSecurity'])];
    }
}

// The single "Your copy" message to the CC addresses. Returns null (no copy wanted) or its own result.
function send_copy(string $id, array &$state, array $s, ?string $png): ?array
{
    if (!$state['cc']) {
        return null;
    }
    if ($state['sent'] === 0) {
        return ['ok' => null, 'skipped' => true];
    }
    if ($state['cc_state'] === 'sending') { // an earlier request died during it: never send it twice
        return ['ok' => false, 'err' => 'Not confirmed: your copy may or may not have arrived.'];
    }
    $state['cc_state'] = 'sending';
    send_save($id, $state);
    $n = $state['sent'];
    $note = 'Your copy. This email was sent to ' . $n . ($n === 1 ? ' customer.' : ' customers.');
    $msg = $note . "\n\n" . $state['msg'];
    [$m, $why] = send_connect($s);
    if ($m === null) {
        return ['ok' => false, 'err' => $why];
    }
    try {
        mail_send_one(
            $m, $s, $state['cc'], $state['subject'],
            email_html($state['ad'], business_name(), $msg, $state['details'], $state['image']),
            email_text($state['ad'], business_name(), $msg, $state['details'], $state['image']),
            $png
        );
        $m->smtpClose();
        return ['ok' => true];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return ['ok' => false, 'err' => mail_explain($e, '', $s['smtpSecurity'])];
    }
}

function send_chunk(string $id): array
{
    ignore_user_abort(true); // a request the browser gave up on still finishes and saves its progress
    @set_time_limit(45);     // a chunk stops by itself long before this
    $start = microtime(true);
    if (!send_valid_id($id) || !is_file(send_path($id, 'json'))) {
        return [404, ['error' => 'This send was already finished or has expired.']];
    }
    $lock = fopen(send_path($id, 'lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return [200, ['busy' => true]]; // another request is working on this send; it must not run twice at once
    }
    try {
        return send_run($id, $start);
    } catch (Throwable $e) {
        error_log('Ad Manager: unexpected error while sending a batch (' . get_class($e) . ').'); // never details or addresses
        return [500, ['error' => 'Something went wrong while sending. Please try again.']];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        if (!is_file(send_path($id, 'json'))) {
            @unlink(send_path($id, 'lock')); // the send is over
        }
    }
}

function send_run(string $id, float $start): array
{
    $state = send_load($id);
    if ($state === null || $state['created'] < time() - SEND_KEEP) {
        send_clear($id);
        return [404, ['error' => 'This send was already finished or has expired.']];
    }
    $s = get_settings();
    $total = count($state['to']);

    // An earlier request died while one message was on its way. Whether it arrived is unknown, so it is
    // listed as unconfirmed and never sent again.
    if ($state['inflight'] !== null) {
        $state['unconfirmed'][] = ['to' => $state['to'][$state['inflight']]];
        $state['next'] = $state['inflight'] + 1;
        $state['inflight'] = null;
        send_save($id, $state);
    }

    $png = null;
    if ($state['image']) {
        $png = @file_get_contents(send_path($id, 'png'));
        if ($png === false) {
            return [500, ['error' => 'The flyer picture for this send is missing. Please start the send again.']];
        }
    }
    $html = email_html($state['ad'], business_name(), $state['msg'], $state['details'], $state['image']);
    $text = email_text($state['ad'], business_name(), $state['msg'], $state['details'], $state['image']);

    $m = null;
    $handled = 0;
    $slow = false;
    while ($state['next'] < $total && $handled < SEND_CHUNK && !$slow && microtime(true) - $start < SEND_BUDGET) {
        if ($m === null || !$m->getSMTPInstance()->connected()) {
            [$m, $why] = send_connect($s);
            if ($m === null) { // cannot connect or sign in: nothing moves
                return [502, ['error' => $why] + send_view($state)];
            }
        }
        $i = $state['next'];
        $to = $state['to'][$i];
        $state['inflight'] = $i; // from here until the answer is saved this one is "unconfirmed" if we die
        send_save($id, $state);
        $began = microtime(true);
        $error = null;
        try {
            mail_send_one($m, $s, $to, $state['subject'], $html, $text, $png);
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $error = $e;
        }
        $took = microtime(true) - $began;
        $state['inflight'] = null;
        $state['next'] = $i + 1;
        $handled++;

        if ($error === null) {
            $state['sent']++;
            $slow = $took > SEND_SLOW;
        } elseif ($took >= SEND_HUNG || preg_match('/timed out|timeout/i', $error->getMessage())) {
            // The server stopped answering: we cannot know whether this one arrived. Stop right here.
            $state['unconfirmed'][] = ['to' => $to];
            send_save($id, $state);
            $m->getSMTPInstance()->close();
            return [502, ['error' => 'The mail server stopped answering. One email may or may not have been delivered. You can try again to carry on with the rest.'] + send_view($state)];
        } else {
            $state['failed'][] = ['to' => $to, 'err' => mail_explain($error, '', $s['smtpSecurity'])];
            // Start a clean conversation, so the next recipient is not tangled up with this refusal.
            try {
                if (!$m->getSMTPInstance()->reset()) {
                    $m->getSMTPInstance()->close();
                }
            } catch (Throwable $e) {
                $m->getSMTPInstance()->close();
            }
        }
        send_save($id, $state);
        if ($state['next'] < $total && $handled < SEND_CHUNK) {
            usleep(SEND_PAUSE_US);
        }
    }
    if ($m !== null && $m->getSMTPInstance()->connected()) {
        $m->smtpClose();
    }

    // Not everyone is done yet, or this request has used up its time: the page calls again.
    if ($state['next'] < $total || microtime(true) - $start >= SEND_BUDGET) {
        return [200, ['done' => false] + send_view($state)];
    }

    $copy = send_copy($id, $state, $s, $png);
    $body = ['done' => true] + send_view($state) + ['cc' => $copy];
    send_clear($id);
    return [200, $body];
}
