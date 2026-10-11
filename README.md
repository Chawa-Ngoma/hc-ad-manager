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
