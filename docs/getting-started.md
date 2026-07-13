# Getting started

How to set up, run, and log in to the Customer Portal locally.

## Requirements

- PHP **8.3+** (with `pdo_pgsql` for PostgreSQL)
- Composer
- Node.js + npm
- **PostgreSQL** (what production runs) — or MySQL/MariaDB/SQLite for quick
  local work; the codebase supports all of them.

## Database

**Production runs PostgreSQL** (see [DEPLOYMENT.md](DEPLOYMENT.md)), so
matching it locally is recommended:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=customerportal
DB_USERNAME=customerportal
DB_PASSWORD=customerportal
```

Create the database and user (requires `sudo`):

```bash
sudo -u postgres psql <<'SQL'
CREATE USER customerportal WITH PASSWORD 'customerportal';
CREATE DATABASE customerportal OWNER customerportal;
SQL
```

**MySQL / MariaDB also works** (case-insensitive chat search adapts
automatically — `ILIKE` on Postgres, `LIKE` elsewhere):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=customerportal
DB_USERNAME=customerportal
DB_PASSWORD=customerportal
```

```bash
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS customerportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'customerportal'@'localhost' IDENTIFIED BY 'customerportal';
CREATE USER IF NOT EXISTS 'customerportal'@'127.0.0.1' IDENTIFIED BY 'customerportal';
GRANT ALL PRIVILEGES ON customerportal.* TO 'customerportal'@'localhost';
GRANT ALL PRIVILEGES ON customerportal.* TO 'customerportal'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

## First-time setup

```bash
composer install
npm install
php artisan migrate --seed   # creates tables and the login account
npm run build                # or use the dev command below
```

## Running the app

```bash
composer run dev
```

This runs the PHP server, queue worker, log tailer, and Vite dev server
together. Open the URL it prints (default `http://localhost:8000`) — you'll be
redirected to the login page.

For a production build of the front-end:

```bash
npm run build
```

## Login

The seeder creates three accounts (change the passwords after first login):

| Email                                 | Password   | Role                   |
| ------------------------------------- | ---------- | ---------------------- |
| `alex.gordo@cwglobalpeople.com`       | `password` | super admin            |
| `dennies.salenga@cwglobalpeople.com`  | `password` | admin                  |
| `admin@example.com`                   | `password` | user (local dev only)  |

Admins add members at `/users` — there is no public registration. The super
admin additionally sees org-wide insights (the dashboard's answer-feedback
card) and can manage other admins.

Re-running `php artisan db:seed` is safe — it uses `updateOrCreate`, so it won't
create duplicates.

## Claude chat (optional)

The `/chat` page uses the Claude API. To enable it, set your key in `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-4-8
```

Without a key the chat page loads but shows a "not configured yet" message
instead of replying. The key is only ever read server-side.

## Useful commands

```bash
php artisan migrate:fresh --seed   # wipe + rebuild + reseed the database
php artisan route:list             # see all routes
npm run lint                       # eslint (auto-fix)
npm run types:check                # vue-tsc type check
./vendor/bin/pest                  # run tests
```

## Switching the database engine

DB settings live in `.env`. To switch to SQLite (no server needed) for quick
local work:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database/database.sqlite
```

Then run `php artisan migrate:fresh --seed`.
