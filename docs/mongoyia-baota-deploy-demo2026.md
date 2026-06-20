# Mongoyia BaoTa Deployment - demo2026.mongoyia.com

This guide prepares the demo/test deployment for `demo2026.mongoyia.com`.
It is not a production go-live approval. Production remains blocked until the
plan-listed HTTPS/WSS/payment/monitoring/backup/load/signoff evidence passes.

## Target

- Git repository: `https://github.com/bos432/mongoyia2.0.git`
- BaoTa site root: `/www/wwwroot/demo2026.mongoyia.com`
- BaoTa running directory / document root: `/www/wwwroot/demo2026.mongoyia.com/web`
- Domain: `demo2026.mongoyia.com`
- HTTPS origin: `https://demo2026.mongoyia.com`
- IM public WSS path: `wss://demo2026.mongoyia.com/ws-im`

## 1. Clone Source

```bash
cd /www/wwwroot
git clone https://github.com/bos432/mongoyia2.0.git demo2026.mongoyia.com
cd /www/wwwroot/demo2026.mongoyia.com
```

For later updates:

```bash
cd /www/wwwroot/demo2026.mongoyia.com
git pull
```

## 2. BaoTa Site Settings

Create the BaoTa website for `demo2026.mongoyia.com`.

- Site directory: `/www/wwwroot/demo2026.mongoyia.com`
- Running directory: `/web`
- PHP version: prefer the same major/minor family as the verified CLI runtime,
  currently PHP 8.3 locally.
- Required PHP extensions/functions: `json`, `redis`, `curl`, `libxml`, `dom`,
  `gd`, `fileinfo`, `openssl`, `mbstring`, `pdo_mysql`, `getimagesize`,
  `fsockopen`, `hash_hmac`, and `random_bytes`.
- PHP settings: set `upload_max_filesize` and `post_max_size` to at least `6M`.
- Writable paths for the PHP/web user: `runtime`, `frontend/runtime`,
  `web/assets`, `web/attachment`, and `web/attachment/chat`.

Recommended Nginx rewrite:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}

location ~ /\.(env|git|svn) {
    deny all;
}
```

Enable SSL for `demo2026.mongoyia.com` in BaoTa before running strict checks.
Do not commit ACME challenge files or private keys into Git.

## 3. Install PHP Dependencies

```bash
cd /www/wwwroot/demo2026.mongoyia.com
composer install --no-dev --prefer-dist --optimize-autoloader
```

If BaoTa cannot run Composer as the site user, install with a shell user and
then fix ownership/permissions for `vendor`, `runtime`, `frontend/runtime`,
`web/assets`, and `web/attachment`.

## 4. Configure Environment

For demo/test-server acceptance:

```bash
cd /www/wwwroot/demo2026.mongoyia.com
cp .env.test.example .env
```

Edit `.env` on the server and replace every placeholder with real test-server
values. At minimum, configure:

- `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`
- `YII_ENV=test`
- `STORE_PLATFORM_DOMAIN=demo2026.mongoyia.com`
- `WEB_BASE_URL=https://demo2026.mongoyia.com`
- `IM_WEBSOCKET_URL=wss://demo2026.mongoyia.com/ws-im`
- identical long random `IM_AUTH_SECRET` in PHP and the Python IM service
- Redis host/port/database
- QPay/LianLian sandbox credentials and callback HMAC secrets
- `QPAY_CALLBACK_BASE=https://demo2026.mongoyia.com`
- `LIANLIAN_CALLBACK_BASE=https://demo2026.mongoyia.com`
- `PAYPAL_CALLBACK_BASE=https://demo2026.mongoyia.com` if PayPal gates are used

Keep `.env` only on the server. It is intentionally ignored by Git.

## 5. Database

Create or restore the target database, then run migrations:

```bash
cd /www/wwwroot/demo2026.mongoyia.com
php yii migrate/up --interactive=0
```

If this is a fresh demo/test restore, prepare the acceptance fixture users only
after the database and migrations are ready:

```bash
php yii mongoyia-acceptance-fixture/run --apply=1 --interactive=0
```

Do not commit SQL dumps into Git. Keep backup/dump files outside the repository
or in a server-only backup directory.

## 6. OAuth Keys

Generate OAuth keys on the server. Do not commit private keys into Git:

```bash
cd /www/wwwroot/demo2026.mongoyia.com
openssl genrsa -out common/config/oauth2_private.key 2048
openssl rsa -in common/config/oauth2_private.key -pubout -out common/config/oauth2_public.key
chmod 600 common/config/oauth2_private.key
chmod 644 common/config/oauth2_public.key
```

The default settings point to:

```text
@common/config/oauth2_private.key
@common/config/oauth2_public.key
```

If the backend settings table overrides these paths, keep the override on the
server and outside Git.

## 7. IM / WSS

The PHP repository expects the IM service to be reachable through:

```text
wss://demo2026.mongoyia.com/ws-im
```

If the Python IM project is deployed separately, start it on the server, for
example on `127.0.0.1:8767`, and configure BaoTa/Nginx reverse proxy:

```nginx
location /ws-im {
    proxy_pass http://127.0.0.1:8767;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

Use the same real `IM_AUTH_SECRET` in the PHP `.env` and the IM service `.env`.

## 8. Strict Check

After HTTPS, `.env`, Redis, database, payment sandbox values, PHP upload limits,
and WSS reverse proxy are configured, run:

```bash
cd /www/wwwroot/demo2026.mongoyia.com
php yii deploy-check/run --profile=test --strict=1 --interactive=0
```

The expected deployment gate before browser/business acceptance is:

```text
0 failure(s), 0 warning(s)
```

Then run acceptance against the public domain:

```bash
php yii mongoyia-acceptance/run \
  --baseUrl=https://demo2026.mongoyia.com \
  --profile=test \
  --strict=1 \
  --cleanupAfterRun=1 \
  --interactive=0
```

Finally, use a browser to validate the buyer, merchant, and platform backend
flows on `https://demo2026.mongoyia.com`.

## 9. Current Known Gate

Local browser validation has passed. The demo/test server can only pass strict
checks after the real server-side inputs are filled:

- HTTPS certificate for `demo2026.mongoyia.com`
- WSS reverse proxy for `/ws-im`
- real long IM shared secret
- real test database and Redis config
- QPay/LianLian sandbox credentials
- callback HMAC secrets and callback max-age values
- PHP upload limits at or above the strict threshold
