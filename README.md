# Ad Manager

A phone-first PHP + SQLite web app to edit a flyer and email it to your customers.

Requires PHP 8.1 or newer with the SQLite (`pdo_sqlite`) and OpenSSL extensions.
Laragon turns `pdo_sqlite` off by default: enable it under Menu > PHP > Extensions, or uncomment `extension=pdo_sqlite` in `php.ini`.

## Run locally

```
php -S 127.0.0.1:8000
```

Open http://127.0.0.1:8000/. The first request creates the `data/` folder, `data/app.sqlite` and `data/secret.key`. `data/` is never committed.

## Manual checks after deploying (Apache, shared cPanel hosting)

- `https://your-site/data/app.sqlite` must return 403 (not the file).
- On Linux, `data/secret.key` must have permission 600: `ls -l data/secret.key` should show `-rw-------`.

Setup and deployment instructions will be added here.

## Signing in

On the first visit the app asks you to choose a password (at least 8 characters). After that it asks for that password. Five wrong tries in a row lock sign-in for 60 seconds. A sign-in lasts 30 days on that device. Only a hash of the password is stored.

## Editing the ad

The Ad tab holds the venue, date, time, headline, description, items shown, contacts and payment line. Save changes stores the ad on the server and shows the cleaned version; Undo changes goes back to the last saved version. The first time the app runs it starts with neutral example text and today's date (Africa/Johannesburg).

The flyer preview redraws as you type, without contacting the server. On a phone, "Edit details" and "See the ad" switch between the form and the flyer; from 900 px wide they sit side by side.

Save flyer picture (under the flyer) makes a 1200 x 1800 PNG named Flyer.png from what is on screen, saved or not. On a phone that supports it, it opens the share sheet; otherwise the picture downloads. The phone share sheet needs HTTPS (localhost is fine for testing).

The Settings tab holds the sender name and email, an optional reply-to and always-CC address, the mail server (server name, port, security, username, password and the trust-certificate switch), the most emails to send at one time (1 to 200) and a new app password. Use the details of the email account you created in your hosting control panel. The sender name also shows in the header. The mail password is encrypted with the key in `data/secret.key` before it is stored and is never sent back to the browser; leaving the field empty keeps the saved one. A new app password must be at least 8 characters; leave it empty to keep the current one. Sending is blocked until the sender email and server are filled in.

Mail is sent through PHPMailer 6.12.0 (LGPL 2.1; the unmodified files and its licence are in `vendor/phpmailer/`, and `vendor/.htaccess` blocks direct web access). Under "Mail server (SMTP)", "Send test email" saves the settings, then sends a short message to the address next to it (reply-to, or the sender email, until you type another) and reports success, or a plain sentence saying what to check. Security "None" sends everything, including the password, without encryption. "Trust the server certificate" relaxes the certificate name check and also accepts a self-signed certificate, for sending email only; use it only when a test fails with a certificate error. Sending stops after 20 seconds of waiting on any step. The mail password and the mail server's debug output are never shown or logged; unexpected errors go to the PHP error log as one short line.

The Send tab composes the email. It shows the From line from Settings, a To box that turns the addresses you paste or type (separated by commas, semicolons, spaces or new lines) into chips marked as fine, "check this" or repeated, with a count against the most-emails-at-one-time limit, a CC box filled in from Settings, a subject that follows the venue and date until you change it, and a message that is remembered on this device (the message is the only thing stored there; addresses never leave the page and are never saved). Two switches choose whether the email shows the venue, time and date, and whether the flyer picture is attached. "What customers will see" is the real email, made by the server from the same template used for sending and shown in a sandboxed frame without scripts. Until the sender email and mail server are saved, a note explains that Settings are needed and "Review and send" does not open. "Review and send" shows a summary with "Go back" and a "Send now" button that is switched off for now. The email has an HTML part and a plain-text part, a 600 px layout with inline styles and tappable phone numbers, and the footer "Sent by" followed by the sender name; it has no unsubscribe or opt-out link. The message box takes up to 5,000 characters (a longer paste is cut at that length by the browser).
