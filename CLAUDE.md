# CW Global People — Customer Portal

Laravel 13 + Vue 3 (Inertia) customer portal. Login-only auth (no registration),
top navigation, MySQL.

## Working conventions

- **Keep the docs current.** Whenever you build, add, edit, or remove a feature,
  update the relevant files in [`docs/`](docs/) in the same change — at minimum
  `docs/features.md` and `docs/changelog.md`. Treat the docs as part of the
  deliverable, not an afterthought.
- **Make tunables configurable, not hardcoded.** Anything that might reasonably
  need changing — prompts/guardrails, model names and lists, token limits, API
  keys, feature toggles, labels — must be read from `.env` (surfaced through a
  `config/*.php` value with a sensible default), never hardcoded in a controller
  or component. For multi-line values (e.g. a system prompt), keep the readable
  default in `config/` and allow a single-line `.env` override. Note: an empty
  `KEY=` in `.env` is read as `""`, not "unset" — comment the line out to fall
  back to the config default.

## Stack

- Backend: Laravel 13 (PHP 8.3+), Fortify auth, MySQL.
- Frontend: Vue 3 + Inertia + TypeScript + Tailwind v4 + shadcn-vue, Wayfinder.
- Chat: Claude API via the official `anthropic-ai/sdk` (server-side only).

## Key facts

- Login account: `admin@example.com` / `password` (seeded).
- Run: `composer run dev`. Build: `npm run build`.
- Claude chat needs `ANTHROPIC_API_KEY` in `.env`.

See [`docs/`](docs/) for the full picture.
