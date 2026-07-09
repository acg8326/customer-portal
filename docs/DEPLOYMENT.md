# Deploying CWGP-AIMe

Production deployment guide for the Laravel 13 + Vue 3 (Inertia) portal with
Claude chat, MCP/OAuth integrations, queued webhooks, and SSE streaming.

> **TL;DR of the things that bite you:** keep `APP_KEY` stable (it decrypts all
> stored tokens), run a **queue worker**, disable proxy **buffering** on the SSE
> stream, and serve over **HTTPS** with a correct `APP_URL` (OAuth callbacks
> depend on it).

---

## 1. Server requirements

**Runtime**

- **PHP 8.3+** with extensions: `pdo_pgsql`, `mbstring`, `openssl`, `ctype`,
  `json`, `bcmath`, `curl`, `dom`, `xml`, `fileinfo`, `tokenizer`, `gd`, `zip`.
- **PostgreSQL 14+**
- **Nginx** (or Caddy/Apache) + **PHP-FPM**
- **Supervisor** (or systemd) — to keep the queue worker alive
- **Composer**

**Build-time only** (can run on CI instead of the server)

- **Node 20+/22 + npm** — for `npm ci && npm run build`. If you build in CI and
  ship `public/build/`, the production server does **not** need Node.

Debian/Ubuntu example:

```bash
sudo apt install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring \
  php8.3-curl php8.3-dom php8.3-xml php8.3-gd php8.3-zip php8.3-bcmath \
  postgresql nginx supervisor unzip
```

---

## 2. Database (PostgreSQL)

```bash
sudo -u postgres psql <<'SQL'
CREATE USER cwgp WITH PASSWORD 'strong-password-here';
CREATE DATABASE cwgp_aime OWNER cwgp;
GRANT ALL PRIVILEGES ON DATABASE cwgp_aime TO cwgp;
SQL
```

The app is Postgres-native (`config/database.php` defaults to `pgsql`). Migrations
are Postgres-safe; chat search uses `ILIKE` for case-insensitive matching on
Postgres automatically.

---

## 3. Application setup

```bash
git clone <repo> /var/www/cwgp-aime && cd /var/www/cwgp-aime

composer install --no-dev --optimize-autoloader
npm ci && npm run build           # produces public/build (skip if building in CI)

cp .env.example .env
# edit .env (see §4), then:

php artisan key:generate          # ONCE — see the APP_KEY warning in §4
php artisan migrate --force
php artisan storage:link
php artisan optimize              # config + route + view cache
```

Ownership/permissions: the web user (e.g. `www-data`) must own/write
`storage/` and `bootstrap/cache/`.

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
```

---

## 4. Environment (`.env`) checklist

```dotenv
APP_NAME="CWGP-AIMe"
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # php artisan key:generate — DO NOT change later (see below)
APP_URL=https://portal.yourdomain.com   # MUST be the real HTTPS URL

# PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cwgp_aime
DB_USERNAME=cwgp
DB_PASSWORD=strong-password-here

# Queue + sessions must be durable (not "sync"/"array")
QUEUE_CONNECTION=database         # or redis
SESSION_DRIVER=database           # or redis
SESSION_SECURE_COOKIE=true

# Claude
ANTHROPIC_API_KEY=sk-ant-...

# Optional tunables have sensible defaults (see config/*.php): USAGE_*,
# RATE_LIMIT_*, ANTHROPIC_TOOL_SAFETY, INTEGRATION_WEBHOOK_PROVIDERS, MCP_OAUTH_*.
```

> ⚠️ **`APP_KEY` is sacred.** MCP tokens, OAuth access/refresh tokens, and webhook
> secrets are **encrypted at rest** with `APP_KEY`. If it changes, every stored
> credential becomes undecryptable and users must reconnect everything. Generate
> it once, back it up, and never rotate it casually.

> ⚠️ **Config caching + env.** `php artisan optimize` caches config, so `.env`
> is read only at cache time. Re-run `php artisan optimize` after any `.env`
> change, or clear with `php artisan optimize:clear`.

---

## 5. Nginx (with SSE streaming)

The chat uses **Server-Sent Events** (`/chat/stream`). The #1 deployment bug is a
proxy buffering the stream so replies arrive all-at-once (or time out). Disable
buffering and raise timeouts.

```nginx
server {
    listen 443 ssl http2;
    server_name portal.yourdomain.com;
    root /var/www/cwgp-aime/public;

    ssl_certificate     /etc/letsencrypt/live/portal.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/portal.yourdomain.com/privkey.pem;

    index index.php;
    charset utf-8;
    client_max_body_size 20M;          # chat uploads (see ANTHROPIC_UPLOADS_MAX_SIZE_KB)

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # --- SSE / streaming: do NOT buffer, allow long-lived responses ---
        fastcgi_buffering off;
        gzip off;
        fastcgi_read_timeout 300s;
        proxy_buffering off;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {                               # redirect http -> https
    listen 80;
    server_name portal.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

Get TLS with certbot: `sudo certbot --nginx -d portal.yourdomain.com`.

---

## 6. Queue worker (required)

Webhook events (`chat.completed` → n8n/Zapier/Make) run on the queue — one job
per connected provider, with retries. Keep a worker running via Supervisor:

```ini
; /etc/supervisor/conf.d/cwgp-aime-worker.conf
[program:cwgp-aime-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/cwgp-aime/artisan queue:work --tries=3 --timeout=60 --sleep=1
directory=/var/www/cwgp-aime
user=www-data
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/cwgp-aime/storage/logs/worker.log
stopwaitsecs=65
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start cwgp-aime-worker:*
```

> If you skip the worker and leave `QUEUE_CONNECTION=sync`, webhooks fire inline
> and delay each chat reply — avoid in production.

## 7. Scheduler (optional)

No scheduled tasks ship today, but adding cron now is harmless and future-proof:

```cron
* * * * * cd /var/www/cwgp-aime && php artisan schedule:run >> /dev/null 2>&1
```

---

## 8. HTTPS + OAuth callbacks

MCP one-click OAuth redirects the user to the provider and back to
`https://<APP_URL>/integrations/mcp/oauth/callback`.

- `APP_URL` **must** be the real HTTPS URL (it builds the redirect URI).
- Providers that require pre-registered redirect URIs (e.g. HubSpot, Atlassian)
  must allowlist that exact callback URL.
- The PKCE verifier + CSRF `state` live in the **session** across the redirect —
  keep `SESSION_DRIVER=database`/`redis` and `SESSION_SECURE_COOKIE=true`.
- **Outbound egress:** the server must reach `api.anthropic.com`, the MCP
  servers, and webhook targets. The SSRF guard blocks private/loopback IPs by
  design — allow outbound HTTPS in your firewall.

---

## 9. Deploy / update routine

```bash
cd /var/www/cwgp-aime
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build            # or pull prebuilt public/build from CI
php artisan migrate --force
php artisan optimize
php artisan queue:restart          # workers pick up new code
php artisan up
```

---

## 10. Backups & security

- **Back up `APP_KEY`** (in a secrets manager) — losing it orphans every stored
  credential.
- **Back up the database** (`pg_dump cwgp_aime`) on a schedule.
- Seeded admins (`php artisan db:seed`): `alex.gordo@cwglobalpeople.com` and
  `dennies.salenga@cwglobalpeople.com`, both password `password` — **change these
  after first login**. Admins add everyone else at `/users`; there is no public
  registration. The seeded `admin@example.com` is a local-dev login (role `user`)
  — remove it before go-live.
- Keep `APP_DEBUG=false` in production.

---

## 11. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Chat replies appear all at once / time out | Proxy buffering the SSE stream — see §5 (`fastcgi_buffering off`, `X-Accel-Buffering: no` is already sent by the app) |
| "Couldn't reach your connected tools" in chat | MCP server URL wrong/unreachable — re-add with the correct MCP endpoint (the validator now catches this on connect) |
| OAuth "invalid/expired authorization response" | Session lost across redirect — check `SESSION_DRIVER`, cookie/secure settings, and that `APP_URL` matches the domain |
| Users must reconnect all tools after a deploy | `APP_KEY` changed — restore the original |
| Webhooks never fire | Queue worker not running, or `QUEUE_CONNECTION=sync` |
| 500 on first request after `.env` edit | Stale config cache — `php artisan optimize:clear` then `optimize` |
