# Getting started

How to set up, run, and log in to the Customer Portal locally.

## Requirements

- PHP **8.3+**
- Composer
- Node.js + npm
- MySQL / MariaDB running locally (the project currently uses MariaDB 11.8)

## Database

The app is configured for **MySQL** in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=customerportal
DB_USERNAME=customerportal
DB_PASSWORD=customerportal
```

The `customerportal` database and user must exist. If you ever need to recreate
them (requires DB admin / `sudo`):

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

The seeder creates one account:

| Email               | Password   |
| ------------------- | ---------- |
| `admin@example.com` | `password` |

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
