# Features — current state

Everything the Customer Portal can do right now. Items marked **(placeholder)**
are wired up and reachable but don't have real functionality yet.

## 1. Authentication

**Login only — no public registration.**

| Feature | Status | Route | Notes |
| --- | --- | --- | --- |
| Email + password login | ✅ Active | `GET/POST /login` | Backed by Laravel Fortify |
| "Remember me" | ✅ Active | — | Checkbox on the login form |
| Logout | ✅ Active | `POST /logout` | From the user menu (top-right) |
| Registration | ❌ Removed | — | Disabled in Fortify; no sign-up anywhere |
| Forgot / reset password | ✅ Active | `/forgot-password`, `/reset-password` | "Forgot password?" link on login |
| Email verification | ✅ Active | `/email/verify` | Dashboard & Chat require a verified email |
| Two-factor authentication (2FA) | ✅ Active | under Settings → Security | TOTP + recovery codes |
| Passkeys (WebAuthn) | ✅ Active | under Settings → Security | Passwordless login option |

**Rate limiting:** The login / 2FA / passkey throttles were **removed** — you can
attempt login as many times as you want. (The password-change endpoint under
settings still has a `6/min` throttle, which we left in place.)

## 2. Login screen (custom design)

A bespoke, modern "AI product" login:

- Full-bleed **deep-ocean dark** scene with an **animated sonar-ping background**
  (HTML Canvas; respects `prefers-reduced-motion`).
- **Frosted-glass** sign-in card with a cyan accent and a glowing Sign-in button.
- Monospace "instrument" labels (e.g. `CW GLOBAL PEOPLE · CUSTOMER PORTAL`).
- Component: [`resources/js/components/SonarBackground.vue`](../resources/js/components/SonarBackground.vue),
  layout: [`resources/js/layouts/AuthLayout.vue`](../resources/js/layouts/AuthLayout.vue),
  page: [`resources/js/pages/auth/Login.vue`](../resources/js/pages/auth/Login.vue).

## 3. Navigation

- **Top header bar** (not a sidebar). Layout:
  [`AppHeaderLayout.vue`](../resources/js/layouts/app/AppHeaderLayout.vue).
- Brand: **CW Global People** (logo text in
  [`AppLogo.vue`](../resources/js/components/AppLogo.vue)).
- Nav items: **Dashboard**, **Chat**.
- Right side: search, and a user menu (settings, logout).
- The old **Repository** and **Documentation** links were removed.

## 4. Pages

| Page | Route | Status |
| --- | --- | --- |
| Dashboard | `/dashboard` | **(placeholder)** — empty card grid |
| Chat | `/chat` | ✅ Working — AI chat powered by the Claude API (see §7) |
| Settings → Profile | `/settings/profile` | ✅ Update name & email, delete account |
| Settings → Security | `/settings/security` | ✅ Change password, manage 2FA & passkeys |
| Settings → Appearance | `/settings/appearance` | ✅ Light / dark / system theme |

`/` (root) redirects: → `/dashboard` when logged in, → `/login` otherwise.

## 5. Account management (Settings)

- **Profile:** change name and email; delete account (requires verified email).
- **Security:** change password; enable/disable two-factor auth (with QR code
  and recovery codes); register and manage passkeys. Viewing this page requires
  re-confirming your password.
- **Appearance:** switch between light, dark, and system themes (persisted).

## 7. Chat — AiMe BOT (Claude AI)

A working AI chat assistant called **AiMe BOT** on the `/chat` page, powered by
the **Claude API**.

- **Modern UI:** branded gradient bot avatar, "Online" status, per-message
  avatars (bot + user initials), a typing indicator, and a soft ambient
  cyan→indigo glow behind the empty state (so it isn't flat in dark mode).
- **Personalized empty state:** a time-of-day greeting using the logged-in
  user's name — "Good morning/afternoon/evening, {name}" — with a simple
  "Hi, I'm AiMe BOT. Ask me anything." subtitle.
- **Model switching is live:** changing the model in the header applies to the
  next message (no refresh); you can even switch models mid-conversation.

> Note: the chat follows the app's light/dark theme (Settings → Appearance). The
> dark background is dark mode, not the chat itself.

- **Model:** picked by the user from a dropdown in the chat header (Opus 4.8,
  Sonnet 4.6, Haiku 4.5). Defaults to `ANTHROPIC_MODEL`; the choice is remembered
  in the browser (`localStorage`). The allowlist lives in
  `config/services.php` (`anthropic.models`) and is validated server-side.
- **How it works:** the Vue page ([`Chat.vue`](../resources/js/pages/Chat.vue))
  keeps the conversation in-page and POSTs the full history to
  `POST /chat/message`. The server-side
  [`ChatController`](../app/Http/Controllers/ChatController.php) calls Claude via
  the official `anthropic-ai/sdk` and returns the reply as JSON. **The API key
  never reaches the browser.**
- **Auth:** the endpoint is behind `auth` + `verified` middleware.
- **Config (all in `.env`):** `ANTHROPIC_API_KEY` (required),
  `ANTHROPIC_MODEL` (default model), `ANTHROPIC_MAX_TOKENS` (reply length), and
  `ANTHROPIC_SYSTEM_PROMPT` (the guardrails/persona — single-line override; the
  multi-line default lives in `config/services.php`). The model **allowlist** is
  also in `config/services.php` (`anthropic.models`). Without a key the chat
  shows a friendly "not configured yet" message instead of erroring.
- **Layout:** a two-pane bordered card — a **conversation sidebar** (new chat,
  history list, delete) plus the chat. Responsive: the sidebar becomes a drawer
  on mobile.
- **Saved chats (persistence):** conversations and messages are stored in the
  database **per user** (`conversations` + `messages` tables). Chats survive a
  refresh, appear in the sidebar (most-recent first), and reopen with their full
  history. The server is the source of truth — each turn sends only the new
  message + conversation id, and history is loaded from the DB. All endpoints
  are scoped to the authenticated user.
- **Current limits (simple-first):** non-streaming (a "Thinking…" indicator
  shows while waiting). No projects/memory yet — see [roadmap.md](roadmap.md).

## 6. Theming

- Light / dark / system mode, with the preference remembered across sessions.
- Built on Tailwind CSS v4 + shadcn-vue design tokens.

## What's NOT built yet

- **Chat** persistence (history is in-page only) and streaming responses.
- Real **Dashboard** content — only placeholder cards.
- Any customer-specific domain models (the only model is `User`).
- A custom brand **logo icon** — still the default Laravel mark; only the text
  was rebranded.
