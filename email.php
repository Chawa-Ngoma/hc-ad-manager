<?php
// The email customers get: an HTML part and a plain-text part built from one ad, plus address parsing.
// Needs lib.php (h, clean_text). Everything that came from a person is escaped in the HTML and stripped of
// control characters in the text; no part of it is ever used as markup.

const EMAIL_WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const EMAIL_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
    'September', 'October', 'November', 'December'];
const EMAIL_MESSAGE_MAX = 5000; // the page's message box has the same limit; this is the server's backstop

// "Wednesday 30 September 2026" from "2026-09-30" (clean_ad has already checked it is a real date).
function email_date(string $iso): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
    if (!$d) {
        return '';
    }
    return EMAIL_WEEKDAYS[(int) $d->format('w')] . ' ' . (int) $d->format('j') . ' ' . EMAIL_MONTHS[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
}

// "09:30 AM" from "09:30".
function email_time(string $hhmm): string
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return sprintf('%02d:%02d %s', $h % 12 ?: 12, $m, $h < 12 ? 'AM' : 'PM');
}

// A tel: link made only of digits and a leading +, or '' when the number has no digits.
function email_tel(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    return $digits === '' ? '' : 'tel:' . (str_starts_with(trim($phone), '+') ? '+' : '') . $digits;
}

// The message as paragraphs: a blank line starts a new paragraph; inside one, "\n" is a line break.
// Control characters go; the text is cut to EMAIL_MESSAGE_MAX characters.
function email_paragraphs(string $message): array
{
    $message = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $message);
    $message = preg_replace('/[^\P{Cc}\n]/u', '', $message); // null for invalid UTF-8
    if ($message === null) {
        return [];
    }
    preg_match('/^.{0,' . EMAIL_MESSAGE_MAX . '}/su', trim($message), $m);
    $message = trim($m[0] ?? '');
    return $message === '' ? [] : preg_split('/\n[ ]*\n\s*/', $message);
}

// Non-empty contacts as [name, phone] pairs.
function email_contacts(array $ad): array
{
    $out = [];
    foreach ([1, 2, 3] as $i) {
        if ($ad["c{$i}n"] !== '' || $ad["c{$i}p"] !== '') {
            $out[] = [$ad["c{$i}n"], $ad["c{$i}p"]];
        }
    }
    return $out;
}

// The optional second footer line, from APP_CREDIT in config.php. One line of at most 100 characters: control
// characters and line breaks are gone. The HTML escapes it; the text part shows it as typed. '' when not set.
function email_credit(): string
{
    return defined('APP_CREDIT') ? clean_text((string) APP_CREDIT, 100) : '';
}

// $ad must come from clean_ad(). Table layout, 600 px wide, inline styles only, light colours in every mail app.
function email_html(array $ad, string $businessName, string $message, bool $showDetails, bool $hasImage): string
{
    $label = 'font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#8a2f28;font-weight:bold;padding:4px 14px 4px 0;vertical-align:top';
    $body = '';
    foreach (email_paragraphs($message) as $p) {
        $body .= '<p style="margin:0 0 14px;overflow-wrap:anywhere">'
            . implode('<br>', array_map('h', explode("\n", $p))) . '</p>';
    }
    if ($showDetails) {
        $rows = ['Venue' => $ad['venue'], 'Time' => email_time($ad['time']), 'Date' => email_date($ad['date'])];
        $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;background-color:#fbf3ee;border-radius:12px"><tr><td style="padding:12px 16px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">';
        foreach ($rows as $name => $value) {
            $body .= '<tr><td style="' . $label . '">' . $name . '</td><td style="font-weight:bold;padding:4px 0;overflow-wrap:anywhere">' . h($value) . '</td></tr>';
        }
        $body .= '</table></td></tr></table>';
    }
    if ($ad['pay'] !== '') {
        $body .= '<p style="margin:0 0 14px;font-size:15px">' . h($ad['pay']) . '</p>';
    }
    $lines = [];
    foreach (email_contacts($ad) as [$name, $phone]) {
        $tel = email_tel($phone);
        $number = $phone === '' ? '' : ($tel === '' ? h($phone) : '<a href="' . h($tel) . '" style="color:#8a2f28;font-weight:bold;text-decoration:underline">' . h($phone) . '</a>');
        $lines[] = ($name !== '' ? h($name) . ($number !== '' ? ': ' : '') : '') . $number;
    }
    if ($lines) {
        $body .= '<p style="margin:0 0 14px;font-size:15px">' . implode('<br>', $lines) . '</p>';
    }
    if ($hasImage) {
        $body .= '<p style="margin:0;font-size:13px;color:#6d5b57;background-color:#f3e8e4;border-radius:8px;padding:8px 12px">The flyer is attached to this email as a picture.</p>';
    }

    return '<!DOCTYPE html>' . "\n"
        . '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
        . '<title>' . h($ad['title']) . '</title></head>' . "\n"
        . '<body style="margin:0;padding:0;background-color:#f6efeb;color:#2b1d1d;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.55">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6efeb"><tr><td align="center" style="padding:16px 8px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:14px">'
        . '<tr><td style="background-color:#c4483f;padding:22px 24px;border-radius:14px 14px 0 0">'
        . '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:1.2;font-weight:bold;color:#ffffff;overflow-wrap:anywhere">' . h($businessName) . '</div>'
        . '<div style="font-size:15px;color:#fbe3df;padding-top:4px;overflow-wrap:anywhere">' . h($ad['title']) . '</div></td></tr>'
        . '<tr><td style="padding:24px;color:#2b1d1d">' . $body . '</td></tr>'
        . '<tr><td style="padding:14px 24px;border-top:1px solid #efe1dc;font-size:12px;color:#8a7772">Sent by ' . h($businessName)
        . (email_credit() !== '' ? '<br>' . h(email_credit()) : '') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

// The same content as plain text. Single-line values lose control characters; nothing is escaped because
// plain text has no markup, so a tag typed by a person simply stays as typed text.
function email_text(array $ad, string $businessName, string $message, bool $showDetails, bool $hasImage): string
{
    $one = fn($v) => clean_text((string) $v, 500);
    $out = [$one($businessName), $one($ad['title']), ''];
    foreach (email_paragraphs($message) as $p) {
        array_push($out, $p, '');
    }
    if ($showDetails) {
        array_push($out, 'Venue: ' . $one($ad['venue']), 'Time: ' . email_time($ad['time']), 'Date: ' . email_date($ad['date']), '');
    }
    if ($ad['pay'] !== '') {
        array_push($out, $one($ad['pay']), '');
    }
    $contacts = email_contacts($ad);
    foreach ($contacts as [$name, $phone]) {
        $out[] = trim($one($name) . ($name !== '' && $phone !== '' ? ': ' : '') . $one($phone));
    }
    if ($contacts) {
        $out[] = '';
    }
    if ($hasImage) {
        array_push($out, 'The flyer is attached to this email as a picture.', '');
    }
    $out[] = 'Sent by ' . $one($businessName);
    if (email_credit() !== '') {
        $out[] = email_credit();
    }
    return implode("\n", $out) . "\n";
}

// Lower-cases, trims, checks and de-duplicates addresses; returns only the good ones, in order.
// Used from the sending step, which gets the addresses as text; the page's own check is only guidance.
function parse_emails(array $list): array
{
    $out = [];
    foreach ($list as $item) {
        if (!is_string($item)) {
            continue;
        }
        $address = strtolower(trim($item));
        if (strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL) && !in_array($address, $out, true)) {
            $out[] = $address;
        }
    }
    return $out;
}
