# Health & Comfort Ad Manager

A phone-friendly web app for creating and emailing promotional flyers.

Requires PHP 8.1 or newer with the SQLite (`pdo_sqlite`) and OpenSSL extensions.
Laragon turns `pdo_sqlite` off by default: enable it under Menu > PHP > Extensions, or uncomment `extension=pdo_sqlite` in `php.ini`.

## Run locally

```
php -S 127.0.0.1:8000
```

Open http://127.0.0.1:8000/. The first request creates the `data/` folder, `data/app.sqlite` and `data/secret.key`. `data/` is never committed.

## Manual checks after deploying (Apache)

- `https://your-site/data/app.sqlite` must return 403 (not the file).
- On Linux, `data/secret.key` must have permission 600: `ls -l data/secret.key` should show `-rw-------`.

Setup and deployment instructions will be added here.
