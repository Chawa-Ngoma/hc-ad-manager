<?php
// Sending mail through the owner's SMTP server with PHPMailer.
// Nothing here echoes or logs the password, the settings or SMTP debug output (debug stays off).
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

// The two settings sending cannot work without.
function smtp_ready(array $s): bool
{
    return $s['smtpHost'] !== '' && $s['senderEmail'] !== '';
}

// A configured mailer for the saved settings. The Message-ID and the EHLO name use the sender's domain.
function mail_open(array $s): PHPMailer
{
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = $s['smtpHost'];
    $m->Port = $s['smtpPort'];
    $m->Hostname = substr(strrchr($s['senderEmail'], '@'), 1);
    $m->CharSet = 'UTF-8';
    $m->Timeout = 20;
    $m->SMTPKeepAlive = true;
    $m->SMTPDebug = 0;

    if ($s['smtpSecurity'] === 'SSL/TLS') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($s['smtpSecurity'] === 'STARTTLS') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        // "None" means no encryption at all, so do not let PHPMailer switch to STARTTLS by itself.
        $m->SMTPSecure = '';
        $m->SMTPAutoTLS = false;
    }

    // "Trust the server certificate": verify_peer stays on. verify_peer_name off skips the check that the
    // certificate matches the server name; allow_self_signed accepts a certificate that signs itself.
    if ($s['trustCert']) {
        $m->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    }

    if ($s['smtpUser'] !== '') {
        $m->SMTPAuth = true;
        $m->Username = $s['smtpUser'];
        $m->Password = dec($s['smtpPassEnc']);
    }
    return $m;
}

// Sends one message to one address (or, for a copy, to a short list of them). $png is the raw bytes of a PNG to attach, or null.
function mail_send_one(PHPMailer $m, array $s, string|array $to, string $subject, string $html, string $text, ?string $png = null, ?string $pdf = null): void
{
    $m->clearAllRecipients();
    $m->clearReplyTos();
    $m->clearAttachments();
    $m->setFrom($s['senderEmail'], $s['senderName']);
    if ($s['replyTo'] !== '') {
        $m->addReplyTo($s['replyTo'], $s['senderName']);
    }
    foreach ((array) $to as $address) {
        $m->addAddress($address);
    }
    $m->Subject = clean_text($subject, 300); // one line, no control characters, room for a long venue
    $m->isHTML(true);
    $m->Body = $html;
    $m->AltBody = $text;
    if ($png !== null) {
        $m->addStringAttachment($png, 'Flyer.png', PHPMailer::ENCODING_BASE64, 'image/png');
    }
    if ($pdf !== null) {
        $m->addStringAttachment($pdf, 'Flyer.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf'); // the name is ours, never the browser's
    }
    $m->send();
}

// PHPMailer does not say why an SSL/TLS connection (the port 465 style) failed. Try the handshake once more,
// for 3 seconds at most with the same trust rules, and return what PHP reports.
function mail_tls_reason(array $s): string
{
    $reason = '';
    set_error_handler(function ($no, $text) use (&$reason) {
        $reason .= ' ' . $text;
        return true;
    });
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => !$s['trustCert'], 'allow_self_signed' => $s['trustCert']]]);
    $conn = stream_socket_client('ssl://' . $s['smtpHost'] . ':' . $s['smtpPort'], $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $context);
    restore_error_handler();
    if ($conn) {
        fclose($conn);
    }
    return trim($reason . ' ' . $errstr);
}

// One plain sentence for a failure. $detail is extra text used only to recognise the failure (see mail_tls_reason).
// $security is the saved Security setting; a failed login with "None" gets a hint about it.
// Server text is cleaned of control characters and cut to about 200 characters.
function mail_explain($error, string $detail = '', string $security = ''): string
{
    $msg = $error instanceof Throwable ? $error->getMessage() : (string) $error;
    $all = $msg . ' ' . $detail;
    if (preg_match('/certificate/i', $all)) {
        return "The server's security certificate does not match its name. If you trust this server, tick 'Trust the server certificate' and try again.";
    }
    if (preg_match('/could not authenticate|authentication|\b535\b/i', $all)) {
        $login = 'The mail server did not accept the username or password.';
        // Some servers only log in over a secure connection and give the same answer when there is none.
        return $security === 'None' ? $login . ' If your mail account needs a secure connection, change the Security setting.' : $login;
    }
    if (preg_match('/could not connect|connect\(\) failed|failed to connect|connection|timed out|getaddrinfo|php_network|wrong version number|unknown protocol|ssl/i', $all)) {
        return 'Could not reach the mail server. Check the server name, port and security setting.';
    }
    if (preg_match('/recipients failed:\s*\S+:\s*(.*)$/s', $msg, $m)) {
        $msg = $m[1]; // keep the reason, not the address it was about
    }
    $said = preg_replace('/[^\s<>"\',;]+@[^\s<>"\',;]+/', 'the address', clean_text($msg, 200)); // addresses never appear in a message
    return $said === '' ? 'The message could not be sent. Please check your email settings and try again.' : 'The mail server said: ' . $said;
}
