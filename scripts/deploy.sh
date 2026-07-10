#!/usr/bin/env bash
#
# Production deploy for CWGP-AIMe.
# Pulls the latest code and rebuilds the app on the server, in one command.
#
#   bash scripts/deploy.sh
#
# Safe to re-run. Assumes: CentOS/Apache + PHP-FPM, PostgreSQL, a systemd queue
# worker running `php artisan queue:work`, and Composer's platform pinned to the
# server's PHP (8.3). Run it from the project root as the app's deploy user.
#
# Before the FIRST deploy of a new feature, make sure any NEW .env keys exist on
# the server (this release added the COMPOSIO_* keys — see .env.example). The
# script warns if COMPOSIO_API_KEY is missing but does not stop.

set -euo pipefail

# Always operate from the project root (one level up from scripts/).
cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$(pwd)"
echo "==> Deploying from: $ROOT"

# --- Pre-flight: warn about env keys this release expects -------------------
if [[ -f .env ]]; then
    if ! grep -q '^COMPOSIO_API_KEY=..*' .env; then
        echo "!!  WARNING: COMPOSIO_API_KEY is empty/absent in .env."
        echo "    Composio connections (Slack/GitHub/HubSpot/Airtable) will be disabled"
        echo "    until you add the COMPOSIO_* keys (see .env.example). Continuing…"
    fi
else
    echo "!!  WARNING: no .env found — the app will not boot. Continuing anyway."
fi

# --- Pull latest -------------------------------------------------------------
echo "==> git pull (fast-forward only)"
git pull --ff-only

# --- PHP dependencies (production) ------------------------------------------
echo "==> composer install (no-dev, optimized)"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# --- Frontend build ----------------------------------------------------------
echo "==> npm ci && npm run build"
npm ci
npm run build

# --- Database ----------------------------------------------------------------
echo "==> php artisan migrate --force"
php artisan migrate --force

# --- Rebuild framework caches (reads the CURRENT .env) -----------------------
# config:clear first so a stale cached config can't shadow new .env keys.
echo "==> Rebuilding caches"
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# --- Restart the queue worker ------------------------------------------------
# `queue:restart` is worker-name-agnostic: it signals any running worker to exit
# after its current job, and systemd starts a fresh one (with the new code).
echo "==> Restarting queue worker(s)"
php artisan queue:restart

# --- Optional: clear PHP-FPM opcache so new code is served immediately -------
# FPM caches compiled PHP; a reload picks up the new files. Guarded so the script
# still succeeds where sudo/systemctl/php-fpm aren't available or named otherwise.
if command -v systemctl >/dev/null 2>&1; then
    echo "==> Reloading php-fpm (opcache)"
    sudo systemctl reload php-fpm 2>/dev/null \
        || echo "    (skipped — reload php-fpm manually if opcache is on)"
fi

# --- Optional: restore SELinux contexts on freshly built assets --------------
# Only relevant on enforcing SELinux hosts; harmless/no-op otherwise.
if command -v restorecon >/dev/null 2>&1; then
    echo "==> restorecon on public/build"
    restorecon -R public/build 2>/dev/null || true
fi

echo "==> Deploy complete ✅"
