# Ad Manager

A phone-first PHP + SQLite web app to edit a flyer and email it to your customers.

Requires PHP 8.1 or newer with the SQLite (`pdo_sqlite`) and OpenSSL extensions.
Laragon turns `pdo_sqlite` off by default: enable it under Menu > PHP > Extensions, or uncomment `extension=pdo_sqlite` in `php.ini`.

## Run locally

```
php -S 127.0.0.1:8000
```

Open http://127.0.0.1:8000/. The first request creates the `data/` folder, `data/app.sqlite` and `data/secret.key`. `data/` is never committed.

## What to upload

The app is exactly these files and folders. Upload nothing else:

```
index.php   api.php   lib.php   mailer.php   email.php   send.php
manifest.webmanifest
.htaccess
assets/
vendor/            (includes vendor/.htaccess and vendor/phpmailer/)
```

`.htaccess` and `vendor/.htaccess` are hidden files (their names start with a dot). Both must reach the server: they stop visitors downloading the code, the database and the key. Do not upload `README.md`, `docs/`, `tools/`, `.git/` or any `data/` folder from your own computer. The app creates `data/` itself on the first visit. An optional `config.php` (see step 10) is the only other file you may add.

## Install on shared cPanel hosting

The steps follow cPanel's usual layout. Screen and button names may be slightly different on another host; the idea is the same. Use your own domain wherever this guide says `example.com`.

1. **Create the sub-domain.** In cPanel open Domains (some versions call it Subdomains) and create `ads.example.com`. Set its document root to a new folder for the app, for example `ads.example.com`, not `public_html`. The app files go straight into that folder. *Names may differ slightly on other hosts.*
2. **Choose PHP 8.1 or newer.** Open Select PHP Version (or MultiPHP Manager), pick the sub-domain and choose PHP 8.1 or newer. In the extensions list make sure `pdo_sqlite` and `openssl` are ticked. In MultiPHP INI Editor (or Select PHP Version > Options) make sure `display_errors` is Off, so a problem never shows a technical message to visitors; errors still go to the error log. *Names may differ slightly on other hosts.*
3. **Upload the app files.** Open File Manager, click Settings (top right) and turn on "Show Hidden Files (dotfiles)". Open the app folder and upload the files listed above, or upload one zip of them and use Extract; the files must end up directly in the app folder, not in a sub-folder. Then check that `.htaccess` and `vendor/.htaccess` are really there (you only see them with hidden files on). *Names may differ slightly on other hosts.*
4. **Choose the password straight away.** Open `https://ads.example.com/` at once. The first visitor to the site chooses the password (at least 8 characters), so do not leave a gap between uploading and this step. If you are not sure that you were the first, use "Forgotten password" below to choose a new one.
5. **Turn on HTTPS and force it.** In cPanel, run AutoSSL for the sub-domain (SSL/TLS Status > Run AutoSSL) and check `https://ads.example.com/` opens without a warning. Until then the app shows a red notice saying it is not using HTTPS. Then send every visit to HTTPS, either with the host's switch (Domains > "Force HTTPS Redirect") or by adding these lines at the very top of the `.htaccess` on the server:

   ```
   RewriteEngine On
   RewriteCond %{HTTPS} !=on
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

   If you replace `.htaccess` when you update the app, add the lines again; the host's switch avoids that. Optional, and only once HTTPS works everywhere on the sub-domain: tell browsers to always use HTTPS by adding `Header always set Strict-Transport-Security "max-age=31536000"` to the `.htaccess` as well. Browsers remember this for a year, so do not add it earlier. The phone's share sheet for the flyer picture also needs HTTPS. *Names may differ slightly on other hosts.*
6. **Create the mailbox.** In Email Accounts create a mailbox on your own domain, for example `ads@example.com`, with a strong password. Emails are sent from this mailbox. *Names may differ slightly on other hosts.*
7. **Fill in Settings.** Sign in, open Settings and enter:
   - Sender name and Sender email: the name customers see, and the mailbox from step 6.
   - Server name: your host's mail server, shown for the mailbox under Connect Devices (often `mail.example.com`).
   - Port and Security: 465 with SSL/TLS (recommended), or 587 with STARTTLS.
   - Username: the full email address of the mailbox. Password: the mailbox password.
   - "Trust the server certificate": leave it off unless "Send test email" fails with a certificate error.

   Press "Send test email" with an address you can read, and check that it arrives. *Names may differ slightly on other hosts.*
8. **Check SPF and DKIM.** In cPanel open Email Deliverability, find the domain and check that it reports SPF and DKIM as valid; use the Repair button if it does not. Then send a test to a Gmail address, open the message, choose "Show original" and check it says `SPF: PASS` and `DKIM: PASS`. Mail without them is often put in spam. *Names may differ slightly on other hosts.*
9. **Set the sending limit.** Hosts limit how many emails you can send per hour. Ask your host for the number. Each customer is one email, and the "Your copy" message and test emails count too, so set "Most emails at one time" in Settings to well under half of the hourly limit (for an hourly limit of 200, use 50 or less). Do not send twice within the same hour.
10. **Check that the protected files are protected.** In a browser open each of these on your sub-domain; every one must show "403 Forbidden" (not the file, and not a blank page):

    ```
    /lib.php   /mailer.php   /email.php   /send.php
    /data/app.sqlite   /data/secret.key
    /vendor/phpmailer/PHPMailer.php
    ```

    The sign-in page (`/`), `/assets/app.css` and `/manifest.webmanifest` must open normally. If any protected file opens, the `.htaccess` files are missing or ignored: check hidden files again (step 3), or ask the host to allow `.htaccess` rules. A safer alternative is to keep the data outside the web folder: create a file `config.php` next to `index.php` containing `<?php define('APP_DATA_DIR', '/home/youruser/ad-manager-data');` (a folder outside the app folder, for example). On Linux `data/secret.key` should have permission 600 (`-rw-------`).

## Back up

Copy the whole `data/` folder to your own computer now and then, and always before updating: File Manager > select `data` > Compress > download. It holds the password hash, the saved ad and settings, the sent log and `secret.key`. Keep it private. The saved mail password is encrypted with `secret.key` and cannot be read without it, so a backup of `app.sqlite` without `secret.key` is not enough (if you ever lose the key, type the mail password into Settings again). *Names may differ slightly on other hosts.*

## Update the app

1. Back up `data/` (see above).
2. Upload the new app files over the old ones, overwriting when asked. Never upload a `data/` folder and never delete the one on the server.
3. Make sure `.htaccess` and `vendor/.htaccess` are still there, and add the HTTPS lines to `.htaccess` again if you added them by hand.
4. Open the site, sign in (you stay signed in) and repeat the checks in step 10.

## Forgotten password

You only need File Manager. Settings, the saved ad and the sent log are kept.

1. In File Manager turn on "Show Hidden Files", open the app folder and then its `data` folder.
2. Create a new empty file in `data` named exactly `reset-password.txt` (File Manager > + File). Its content does not matter.
3. Within 60 minutes of creating it, open the sign-in page. It now says "Reset mode is on. Choose a new password." Type a new password (at least 8 characters) twice and press "Save password and continue".
4. The app deletes the file, signs out every device that was signed in before, clears any sign-in lockout and signs you in. Everyone else must now use the new password.

To cancel, delete `reset-password.txt` from `data`. A file older than 60 minutes (or dated in the future) is ignored and removed, so just create it again. While the file exists anyone who opens the sign-in page can choose the password, so do steps 2 and 3 together and straight away. As a last resort, deleting `data/app.sqlite` also lets you choose a new password, but it erases the settings, the ad and the sent log. *Names may differ slightly on other hosts.*

## Other checks

- If the site is not on HTTPS (and the address is not localhost), a red notice on the sign-in card and at the top of the app says so. Turn HTTPS on and it goes away.
- If something unexpected breaks on the server, the owner sees one short sentence, never a stack trace; the details (the error class, file and line, never a password or an address) go to the PHP error log.
- The server does not announce its PHP version in a response header.

## Signing in

On the first visit the app asks you to choose a password (at least 8 characters). After that it asks for that password. Five wrong tries in a row lock sign-in for 60 seconds. A sign-in lasts 30 days on that device. Only a hash of the password is stored.

## Editing the ad

The Ad tab holds the venue, date, time, headline, description, items shown, contacts and payment line. Save changes stores the ad on the server and shows the cleaned version; Undo changes goes back to the last saved version. The first time the app runs it starts with neutral example text and today's date (Africa/Johannesburg).

The flyer preview redraws as you type, without contacting the server. On a phone, "Edit details" and "See the ad" switch between the form and the flyer; from 900 px wide they sit side by side.

Save flyer picture (under the flyer) makes a 1200 x 1800 PNG named Flyer.png from what is on screen, saved or not. On a phone that supports it, it opens the share sheet; otherwise the picture downloads. The phone share sheet needs HTTPS (localhost is fine for testing).

The Settings tab holds the sender name and email, an optional reply-to and always-CC address, the mail server (server name, port, security, username, password and the trust-certificate switch), the most emails to send at one time (1 to 200) and a new app password. Use the details of the email account you created in your hosting control panel. The sender name also shows in the header. The mail password is encrypted with the key in `data/secret.key` before it is stored and is never sent back to the browser; leaving the field empty keeps the saved one. A new app password must be at least 8 characters; leave it empty to keep the current one. Sending is blocked until the sender email and server are filled in.

Mail is sent through PHPMailer 6.12.0 (LGPL 2.1; the unmodified files and its licence are in `vendor/phpmailer/`, and `vendor/.htaccess` blocks direct web access). Under "Mail server (SMTP)", "Send test email" saves the settings, then sends a short message to the address next to it (reply-to, or the sender email, until you type another) and reports success, or a plain sentence saying what to check. Security "None" sends everything, including the password, without encryption. "Trust the server certificate" relaxes the certificate name check and also accepts a self-signed certificate, for sending email only; use it only when a test fails with a certificate error. Sending stops after 20 seconds of waiting on any step. The mail password and the mail server's debug output are never shown or logged; unexpected errors go to the PHP error log as one short line.

The Send tab composes the email. It shows the From line from Settings, a To box that turns the addresses you paste or type (separated by commas, semicolons, spaces or new lines) into chips marked as fine, "check this" or repeated, with a count against the most-emails-at-one-time limit, a CC box filled in from Settings, a subject that follows the venue and date until you change it, and a message that is remembered on this device (the message is the only thing stored there; addresses never leave the page and are never saved). Two switches choose whether the email shows the venue, time and date, and whether the flyer picture is attached. "What customers will see" is the real email, made by the server from the same template used for sending and shown in a sandboxed frame without scripts. Until the sender email and mail server are saved, a note explains that Settings are needed and "Review and send" does not open. "Review and send" shows a summary with "Go back" and "Send now". The email has an HTML part and a plain-text part, a 600 px layout with inline styles and tappable phone numbers, and the footer "Sent by" followed by the sender name; it has no unsubscribe or opt-out link. The message box takes up to 5,000 characters (a longer paste is cut at that length by the browser).

"Send now" saves the ad first, so what goes out is what is saved, then makes the flyer picture (if the picture switch is on) and starts the send. The server keeps the send as a state file in `data/outbox` and the page calls it again and again; each call sends to the next 8 customers, one message each with only that customer in To, waits about 150 milliseconds between messages, and starts no new recipient after about 15 seconds so no request runs long (a mail server that stops answering is given up on after about 12 seconds). The state file is saved around every single recipient and locked while a call is working, so a call that is repeated, dropped or run twice at once never emails anyone twice; if a call dies while one message is on its way, that person is shown as "may or may not have received it" and is never sent to again. A recipient the mail server refuses is listed with the server's reason and the rest still go out; a connection or sign-in problem stops the send with a plain message and "Try again" carries on from the same place. When everyone is done, the CC addresses (up to 5) get one message that starts "Your copy" (not sent if no customer's address was accepted); a problem with it is reported separately. The flyer picture must be a PNG under 6 MB, and the server's own upload limit (`upload_max_filesize`, `post_max_size`) must allow it. Each call assumes the host lets a PHP request run for at least 30 seconds (it asks for 45 and normally uses far less). Customer addresses are kept only in the state file, never in a log or message, and the state, picture and lock files are deleted when the send finishes. A send that is abandoned part-way leaves its state file in `data/outbox` until the next send starts or 24 hours pass, when it is deleted.

The "Recent sends" card at the bottom of the Send tab lists the 10 most recent sends, newest first: the subject, when it was sent (Johannesburg time), "x of y sent", and, when some did not go out, how many "did not go through or could not be confirmed". It updates as soon as a send finishes and is also part of the page when it loads. Each finished send is written to the `sends` table exactly once, when its last request finishes, with only counts: accepted customers, and failed plus unconfirmed ones (the "Your copy" message is never counted, and a send where every customer failed still gets a row). No recipient address is stored in the table or anywhere else once a send is over, but the subject is stored as it was sent, so an address typed into the subject would be kept. A send that is abandoned before it finishes has no row. The log also keeps a small marker in the `kv` table for each finished send (the send's random id, no address), written in the same transaction as its row, so a repeated final request cannot log the same send twice; markers older than two days are removed when a new send starts.
