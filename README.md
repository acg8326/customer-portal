# CW Global People — Customer Portal

A Laravel + Vue (Inertia) customer portal with **login-only** authentication (no
public registration), **top navigation**, and **AiMe BOT** — an AI chat
assistant powered by the Claude API.

## Features

- 🔐 **Login-only auth** (Laravel Fortify) — no registration; `/` redirects to
  login or the dashboard.
- 🧭 **Top navigation** — Dashboard and Chat.
- 🤖 **AiMe BOT chat** — Claude-powered assistant with a **model picker**
  (Opus 4.8 / Sonnet 4.6 / Haiku 4.5), a time-of-day greeting, and a modern UI.
- ⚙️ **Configurable** — models, system prompt/guardrails, and token limits all
  live in `.env` (see [docs/](docs/)).
- 🎨 Tailwind v4 + shadcn-vue, light/dark themes.

## Tech stack

Laravel 13 (PHP 8.3+) · Vue 3 + Inertia + TypeScript · Tailwind CSS v4 ·
shadcn-vue · Laravel Wayfinder · MySQL/MariaDB · Claude API (`anthropic-ai/sdk`).

## Quick start

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
#   → set your DB credentials and ANTHROPIC_API_KEY in .env

# 3. Database (creates tables + seeds the login account)
php artisan migrate --seed

# 4. Run (server + queue + logs + vite)
composer run dev
```

Open the app and you'll land on the login page.

### Login

| Email | Password |
| --- | --- |
| `admin@example.com` | `password` |

### Claude chat

The `/chat` page needs a Claude API key. In `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-4-8
```

Without a key the chat loads but shows a "not configured yet" message. The key
is only ever read server-side and **must never be committed** (`.env` is
gitignored).

## Documentation

Full docs live in [`docs/`](docs/):

- [Getting started](docs/getting-started.md) — setup, run, commands
- [Features](docs/features.md) — everything the app does
- [Tech stack](docs/tech-stack.md) — frameworks and project layout
- [Changelog](docs/changelog.md) — what's been built
- [Roadmap](docs/roadmap.md) — parked / future features

## License

MIT
