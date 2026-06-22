# Changelog — what we changed from the base starter kit

This app started as the **Laravel Vue starter kit**. Here's everything we've
customized so far, newest first.

## Chat (Claude AI)
- Made chat tunables **configurable via `.env`** instead of hardcoded: the
  system prompt/guardrails (`ANTHROPIC_SYSTEM_PROMPT`, multi-line default in
  `config/services.php`) and reply length (`ANTHROPIC_MAX_TOKENS`). Established
  a project rule (in `CLAUDE.md`) that tunables live in `.env`/`config`.
- Wrapped the chat in a **bordered card panel** and made the ambient glow show
  in light mode (so it's not a flat, undefined background).
- Polished the empty state: removed the suggestion chips, simplified the copy to
  "Hi, I'm AiMe BOT. Ask me anything.", and added a soft ambient cyan→indigo glow
  so it isn't flat black in dark mode.
- Renamed the assistant to **AiMe BOT** (header, empty state, system prompt) and
  gave the chat a **modern redesign**: gradient bot avatar + "Online" status,
  per-message avatars, suggestion chips, message fade-in, and a personalized
  **time-of-day greeting** ("Good morning, {name}") on the empty state.
- Parked chat **persistence, streaming, and long-term memory** — captured in
  [roadmap.md](roadmap.md).
- Added a **model picker** in the chat header (Opus 4.8 / Sonnet 4.6 / Haiku
  4.5). Allowlist in `config/services.php`, served to the page and validated
  server-side; selection persists in `localStorage`. The chat route now uses
  `ChatController@index` to pass the model list as props.
- Built a working **AI chat** on `/chat` powered by the Claude API
  (`claude-opus-4-8` default) via the official `anthropic-ai/sdk`.
- Added `ChatController` + `POST /chat/message` (auth-protected, returns JSON);
  the API key stays server-side.
- Rebuilt `Chat.vue` into a real chat UI (message bubbles, composer, loading and
  error states). Conversation is in-page; non-streaming for now.
- Added `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` to `.env` + `config/services.php`.

## Branding
- App name set to **CW Global People** (`APP_NAME` in `.env`) — drives page
  titles and the login eyebrow label.
- Header logo text changed from "Laravel Starter Kit" → **CW Global People**.
- (Logo *icon* is still the default Laravel mark — not yet replaced.)

## Navigation
- Switched the authenticated layout from a **sidebar** to a **top header bar**.
- Added a **Chat** nav item + `/chat` route + placeholder page.
- Removed the **Repository** and **Documentation** header links.

## Authentication
- **Removed registration** entirely (disabled the Fortify feature, deleted the
  Register page and its test, removed all sign-up links).
- **Removed rate limiting** on login / 2FA / passkeys (limiters set to `null` in
  `config/fortify.php`; limiter definitions deleted from `FortifyServiceProvider`).
- Still enabled: password reset, email verification, two-factor auth, passkeys.

## Login screen
- Replaced the default login with a custom **"sonar" dark-glass design**:
  animated Canvas background, frosted card, cyan accent.
- New components: `SonarBackground.vue`; reworked `AuthLayout.vue` and
  `auth/Login.vue`.

## Routing
- `/` now **redirects** (dashboard if logged in, else login) instead of showing
  a marketing welcome page.
- Deleted the `Welcome.vue` landing page.

## Database & data
- Configured the app for **MySQL/MariaDB** (`customerportal` db + user).
- Seeder now creates a single login account: `admin@example.com` / `password`
  (idempotent via `updateOrCreate`).

## Known follow-ups
- **Chat:** add streaming responses and persist conversations to the database.
- Build real **Dashboard** content.
- Replace the **logo icon** with a CW Global People mark.
- Decide whether to keep email verification required for dashboard/chat access.
