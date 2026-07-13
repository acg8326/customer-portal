# CW Global People — Customer Portal (CWGP-AIMe)

A Laravel + Vue (Inertia) customer portal with **login-only** authentication (no
public registration), a **collapsible left sidebar**, and **AiMe BOT** — an AI
chat assistant powered by the Claude API, built to feel like claude.ai.

## Features

- 🔐 **Login-only auth** (Laravel Fortify) — no registration; 2FA + passkeys;
  `admin` / `user` roles with member management at `/users`.
- 🤖 **AiMe BOT chat** — streaming Claude assistant with a **model picker**
  (Opus / Sonnet / Haiku / Fable), Markdown rendering, file & image uploads
  (Ctrl+V paste), and a claude.ai-style persona.
- 🧠 **Extended thinking** — a per-session toggle that streams the model's
  thought process into a collapsible block (supported models only).
- 🌐 **Web access** — Claude's native server-side web search + fetch on every
  chat path, with cited **Sources** footers.
- 📄 **Export any answer** — Copy / Markdown / **PDF** / **Word (.docx)**, and
  **CSV / XLSX** when the answer contains a table (native OOXML writers, no
  heavy deps).
- 🔌 **Integrations** — Slack/GitHub/HubSpot/Airtable via Composio, custom MCP
  servers (OAuth), n8n/Zapier/Make/webhook events, and a **native NetSuite**
  integration (Token-Based Auth *and* OAuth 2.0) over SuiteTalk REST + SuiteQL.
- 🛡️ **Tool safety** — confirm-before-destructive-actions guardrail with an
  opt-in auto-approve switch (confirmation dialog), plus an always-on
  prompt-injection defense for tool/web content.
- 🗂️ **Projects & skills** — per-project chats with instructions; reusable
  skill prompts.
- 💬 **claude.ai-parity chat UX** — auto-generated conversation titles,
  manual + automatic **compaction**, Continue on long-reply cutoff, retry,
  edit-and-resend, thumbs feedback, per-user standing **chat preferences**.
- 💸 **Cost controls** — prompt caching on every path, history trimming,
  per-toolkit schema routing, tool-result caps, per-user token budgets, and a
  usage dashboard.
- ⚙️ **Everything configurable** — models, prompts/guardrails, limits, and
  feature toggles live in `.env` with sane defaults in `config/` (see
  [docs/](docs/)).
- 🎨 Tailwind v4 + shadcn-vue, light/dark themes.

## Tech stack

Laravel 13 (PHP 8.3+) · Vue 3 + Inertia + TypeScript · Tailwind CSS v4 ·
shadcn-vue · Laravel Wayfinder · **PostgreSQL** · Claude API
(`anthropic-ai/sdk`).

## Quick start

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
#   → set your PostgreSQL credentials and ANTHROPIC_API_KEY in .env

# 3. Database (creates tables + seeds the login accounts)
php artisan migrate --seed

# 4. Run (server + queue + logs + vite)
composer run dev
```

Open the app and you'll land on the login page.

### Login (seeded)

| Email | Password | Role |
| --- | --- | --- |
| `alex.gordo@cwglobalpeople.com` | `password` | admin |
| `dennies.salenga@cwglobalpeople.com` | `password` | admin |
| `admin@example.com` | `password` | user (local dev) |

Change the passwords after first login. Admins add members at `/users` — there
is no public registration.

### Claude chat

The `/chat` page needs a Claude API key. In `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-4-8
```

Without a key the chat loads but shows a "not configured yet" message. The key
is only ever read server-side and **must never be committed** (`.env` is
gitignored). Validate the chat model picker anytime with
`php artisan chat:check-models`.

## Deploying

On the server, pull and run the one-command deploy (composer, npm build,
migrations, caches, queue restart):

```bash
bash scripts/deploy.sh
```

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for first-time server setup.

## Documentation

Full docs live in [`docs/`](docs/):

- [Getting started](docs/getting-started.md) — setup, run, commands
- [Features](docs/features.md) — everything the app does
- [Tech stack](docs/tech-stack.md) — frameworks and project layout
- [Composio integrations](docs/composio-integrations.md) — connected-tools setup
- [Deployment](docs/DEPLOYMENT.md) — production server guide
- [Changelog](docs/changelog.md) — what's been built
- [Roadmap](docs/roadmap.md) — parked / future features

## License

MIT
