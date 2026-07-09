# Features — current state

Everything the Customer Portal can do right now. Items marked **(placeholder)**
are wired up and reachable but don't have real functionality yet.

## 1. Authentication

**Login only — no public registration.**

| Feature                         | Status     | Route                                 | Notes                                     |
| ------------------------------- | ---------- | ------------------------------------- | ----------------------------------------- |
| Email + password login          | ✅ Active  | `GET/POST /login`                     | Backed by Laravel Fortify                 |
| "Remember me"                   | ✅ Active  | —                                     | Checkbox on the login form                |
| Logout                          | ✅ Active  | `POST /logout`                        | From the user menu (top-right)            |
| Registration                    | ❌ Removed | —                                     | Disabled in Fortify; no sign-up anywhere  |
| Forgot / reset password         | ✅ Active  | `/forgot-password`, `/reset-password` | "Forgot password?" link on login          |
| Email verification              | ✅ Active  | `/email/verify`                       | Dashboard & Chat require a verified email |
| Two-factor authentication (2FA) | ✅ Active  | under Settings → Security             | TOTP + recovery codes                     |
| Passkeys (WebAuthn)             | ✅ Active  | under Settings → Security             | Passwordless login option                 |

**Rate limiting:** The login / 2FA / passkey throttles were **removed** — you can
attempt login as many times as you want. (The password-change endpoint under
settings still has a `6/min` throttle, which we left in place.) Separately, the
**app endpoints** are throttled per-user to stop runaway loops and abuse — chat
send, chat search, and the integration endpoints — with generous, `.env`-tunable
limits (`RATE_LIMIT_*`, `config/ratelimits.php`) that normal use never reaches.

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

- **Left sidebar** (Claude-style), not a top header. Layout:
  [`AppSidebarLayout.vue`](../resources/js/layouts/app/AppSidebarLayout.vue),
  sidebar in [`AppSidebar.vue`](../resources/js/components/AppSidebar.vue).
- **Collapsible:** toggle open/closed with the rail button in the header or
  **⌘/Ctrl-B**. Collapsed, it shrinks to an icon-only rail (labels become
  hover tooltips); expanded, it shows full labels. The open/closed choice is
  **remembered across page loads** (a `sidebar_state` cookie). On mobile it
  slides in as an overlay drawer.
- Brand: **CW Global People** (logo at the top of the sidebar, in
  [`AppLogo.vue`](../resources/js/components/AppLogo.vue)).
- Nav items: **Dashboard**, **Chat**, **Projects**, **Integrations**.
- Also in the sidebar: a **Search** action (or ⌘/Ctrl-K, see §7) and, pinned to
  the bottom, the user menu (settings, logout).
- Main pages still use the full window width; the sidebar sits beside the
  content, which fills the rest of the window.
- The old **Repository** and **Documentation** links were removed.

## 4. Pages

| Page                  | Route                  | Status                                                   |
| --------------------- | ---------------------- | -------------------------------------------------------- |
| Dashboard             | `/dashboard`           | ✅ Token-usage meter + conversation/project/skill counts |
| Chat                  | `/chat`                | ✅ Working — AI chat powered by the Claude API (see §7)  |
| Integrations          | `/integrations`        | ✅ **MCP servers + n8n live**; other cards placeholder    |
| Settings → Profile    | `/settings/profile`    | ✅ Update name & email, delete account                   |
| Settings → Security   | `/settings/security`   | ✅ Change password, manage 2FA & passkeys                |
| Settings → Appearance | `/settings/appearance` | ✅ Light / dark / system theme                           |
| Settings → Skills     | `/settings/skills`     | ✅ Create / import / manage reusable instruction presets |

`/` (root) redirects: → `/dashboard` when logged in, → `/login` otherwise.

## 5. Account management (Settings)

- **Profile:** change name and email; delete account (requires verified email).
- **Security:** change password; enable/disable two-factor auth (with QR code
  and recovery codes); register and manage passkeys. Viewing this page requires
  re-confirming your password.
- **Appearance:** switch between light, dark, and system themes (persisted).
- **Skills:** create reusable **instruction presets**, add ready-made ones from a
  **starter library** (config `config/skills.php`), or **import a `SKILL.md`**
  (front-matter `name`/`description` + body). Each skill = name, emoji icon,
  description, and instructions. Per-user and owner-checked. Pick one above the
  chat composer to apply it (see §7). Route group `settings/skills`
  ([`SkillController`](../app/Http/Controllers/Settings/SkillController.php)).

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

- **Model:** picked by the user from a dropdown in the chat header. Ships with
  **8 Claude models** — Opus 4.8 / 4.7 / 4.1, Sonnet 5 / 4.6 / 4.5, Haiku 4.5,
  and Fable 5. Defaults to `ANTHROPIC_MODEL`; the choice is remembered in the
  browser (`localStorage`). The allowlist lives in `config/services.php`
  (`anthropic.models`) and is validated server-side — add/remove freely.
- **Skills:** a **skill selector** above the composer applies one of your saved
  Skills (Settings → Skills, §5) to the chat — its instructions are injected into
  the system prompt (single skill at a time). The choice is stored on the
  conversation (`conversations.skill_id`) and restored when you reopen it. Manage
  skills in Settings.
- **Search your chats:** the header **search icon** (or ⌘/Ctrl-K) opens a dialog
  that searches your conversations by **title or message content** and shows a
  matching snippet; clicking a result opens that chat (project chats open in
  their project). Backed by `GET /chat/search`, scoped to the current user.
- **How it works:** the reusable
  [`ChatPanel.vue`](../resources/js/components/ChatPanel.vue) sends the new
  message + conversation id to `POST /chat/message`. The server-side
  [`ChatController`](../app/Http/Controllers/ChatController.php) loads history
  from the DB, calls Claude via the official `anthropic-ai/sdk`, persists both
  sides, and returns the reply as JSON. **The API key never reaches the
  browser.**
- **Auth:** the endpoint is behind `auth` + `verified` middleware.
- **Config (all in `.env`):** `ANTHROPIC_API_KEY` (required),
  `ANTHROPIC_MODEL` (default model), `ANTHROPIC_MAX_TOKENS` (reply length), and
  `ANTHROPIC_SYSTEM_PROMPT` (the guardrails/persona — single-line override; the
  multi-line default lives in `config/services.php`). The model **allowlist** is
  also in `config/services.php` (`anthropic.models`). Without a key the chat
  shows a friendly "not configured yet" message instead of erroring.
- **Layout:** the standalone `/chat` page is **full-bleed** — the **conversation
  sidebar** (new chat, history list, delete) sits flush at the far left and the
  chat fills the full window width (no centered gutters). The conversation and
  composer now span the **full panel width** (no narrow reading column), so there's
  no dead space left/right. Responsive: the sidebar becomes a drawer on mobile.
  (Inside a Project workspace the same `ChatPanel` renders as a bordered card
  beside the knowledge panel.)
- **Saved chats (persistence):** conversations and messages are stored in the
  database **per user** (`conversations` + `messages` tables). Chats survive a
  refresh, appear in the sidebar (most-recent first), and reopen with their full
  history. The server is the source of truth — each turn sends only the new
  message + conversation id, and history is loaded from the DB. All endpoints
  are scoped to the authenticated user.
- **File uploads (images + PDFs):** attach files with the composer paperclip;
  Claude reads them natively. Files are **re-sent every turn** (stored on the
  message), so follow-up questions keep the document in view. Configurable in
  `.env` (`ANTHROPIC_UPLOADS_*`): enable/disable, max files, max size, and
  allowed extensions. Word/Excel are **not** supported yet (would need text
  extraction) — see [roadmap.md](roadmap.md).
- **Token usage:** each reply's input/output tokens (from the Claude API `usage`)
  accumulate on the conversation and show as a small **"N tokens"** pill in the
  composer footer (hover for the in/out breakdown). Resets on New chat.
- **Per-user token budget:** every user has a rolling allowance (default
  **1,000,000 tokens / 30 days**, configurable via `USAGE_*`). Usage is charged
  per reply; once spent, sending is blocked with a "resets on <date>" message
  until the window rolls over. The **Dashboard** shows the live meter.
  ([`TokenBudget`](../app/Services/TokenBudget.php).)
- **Prompt caching + history trimming:** the system prompt is cached
  (`cache_control`) so repeat turns are cheaper, and only the most recent
  `ANTHROPIC_HISTORY_LIMIT` messages (default 40) are replayed each turn, keeping
  long conversations' context and cost bounded.
- **Streaming replies:** the assistant's reply **streams in token-by-token**
  over Server-Sent Events (`POST /chat/stream`) — a "Thinking…" indicator shows
  only until the first token, then the text fills in live. The message, token
  totals, and n8n event are all persisted server-side once the stream finishes
  (identical bookkeeping to the non-streaming path, which remains at
  `POST /chat/message`). File uploads stream too.

## 6. Projects (Claude-style workspaces)

Named workspaces that give the assistant lasting context for a specific job —
modeled on Claude.ai Projects.

- **Projects list** (`/projects`) — create projects, open them, and **delete**
  them (hover a card → trash → click again to confirm).
- **Project workspace** (`/projects/{id}`) — the chat experience scoped to the
  project (its own conversation history) with a **visible right-hand panel**
  ([`ProjectKnowledge.vue`](../resources/js/components/ProjectKnowledge.vue))
  to edit the project's **Name** and **Instructions**, plus a **Delete project**
  button. On small screens the panel opens as a drawer.
- **Instructions are injected** into the system prompt for every chat in the
  project (appended to the base guardrails), so the assistant behaves
  consistently. _(A per-project **Memory** field was removed — not needed for
  now. The DB column is retained but unused.)_
- **Scoping:** a conversation optionally belongs to a project (`conversations.
project_id`). Project chats appear only in that project; standalone `/chat`
  shows only non-project chats. All endpoints are owner-checked.
- Backend: [`ProjectController`](../app/Http/Controllers/ProjectController.php),
  `Project` model, `projects` table. UI:
  [`projects/Index.vue`](../resources/js/pages/projects/Index.vue),
  [`projects/Show.vue`](../resources/js/pages/projects/Show.vue).
- **Not yet:** project-level file attachments (per-message uploads exist in chat,
  §7) — see [roadmap.md](roadmap.md).

## 8. Integrations

Connect AiMe BOT to outside tools. The page (`/integrations`) groups connectors
by category (Communication, CRM, Files & documents, Automation, ERP & business
systems, Productivity & data). Each card has a **Setup guide** modal with
numbered steps.

- **MCP servers (live — native tools).** Connect a **Model Context Protocol**
  server (Slack, GitHub, Notion, … anything exposing MCP) with a name and URL;
  the assistant can then **call that server's tools** during chat. Anthropic runs
  the tool calls **server-side** via the MCP connector. Per-user, secrets
  **encrypted at rest**, URL **SSRF-guarded**. Enable/disable/delete each server.
  **Tool turns stream** like normal chat, and the composer shows
  **"Using &lt;server&gt;…"** while a tool runs. (MCP turns send text-only
  history — per-message image/PDF passthrough is still chat-only.)
  Two ways to authenticate:
    - **One-click OAuth** — pick "One-click OAuth", **Connect**, and you're sent to
      the server's own approve screen (you're already logged in there); approve and
      you're back, connected. AiMe discovers the authorization server
      (RFC 9728 / 8414), **self-registers** via Dynamic Client Registration
      (RFC 7591) where supported (so most servers need no manually-created app),
      runs the **authorization-code + PKCE** flow, and **auto-refreshes** the token
      before it expires. Every discovered URL is SSRF-guarded.
    - **Paste a token** — for servers that use a static bearer token (or none).
  Backend: [`McpServerController`](../app/Http/Controllers/McpServerController.php),
  [`McpOAuthService`](../app/Services/Mcp/McpOAuthService.php), `McpServer` model;
  streamed via `beta.messages` in
  [`ChatController::stream`](../app/Http/Controllers/ChatController.php).
  Config: `ANTHROPIC_MCP_BETA`, `MCP_OAUTH_*`.
- **Apps — one-click connect (catalog).** Popular MCP apps (GitHub, Notion, Linear,
  Sentry, Atlassian, Asana, **HubSpot**, **Airtable**, Stripe, PayPal, Intercom,
  Vercel, …) appear as cards with a single **Connect** button: it creates the MCP server from the catalog
  entry and runs the OAuth flow above — no URL to paste. Cards are **state-aware**
  (Connected / Reconnect / Enable-Disable / remove). The catalog lives in
  `config/integrations.php` (`mcp_catalog`); each URL is overridable via
  `MCP_CATALOG_<APP>_URL`. Any app not listed can still be added by hand.
- **Cross-app interop.** Because every enabled MCP server's tools are offered to
  the model **together** in one turn, the assistant can move/compare data across
  connected apps — e.g. "compare HubSpot deals to the Airtable `Deals` table and
  add the missing ones." No extra wiring; connect both and ask.
- **Destructive-action guardrail.** When the user has tools connected, a policy is
  appended to the system prompt requiring the assistant to **confirm before any
  create / update / delete / send** and wait for explicit approval; reads and
  searches are unrestricted. Toggle with `ANTHROPIC_TOOL_SAFETY`, override the text
  with `ANTHROPIC_TOOL_SAFETY_PROMPT`. **Note:** this is a *policy* guardrail (the
  model complies), not a hard gate — Anthropic's MCP connector executes tools
  server-side within the turn, so there is no per-call approval interrupt. For a
  hard gate, pair it with **least-privilege OAuth scopes** (`MCP_OAUTH_SCOPES`) or
  read-only tokens; a true click-to-approve interrupt would require running tools
  client-side (a larger change, on the roadmap).
- **Automation (live): n8n, Zapier, Make.com, and generic Webhooks.** Paste an
  outbound webhook URL (plus an optional shared secret, sent as a header). AiMe BOT
  **POSTs a `chat.completed` event** to every connected provider after each reply.
  Use **Send test** to fire a `test.ping`, and **Disconnect** to remove it.
  Delivery is on a **queue**, one job per provider (independent retries).
  These are event *targets* (outbound), not OAuth logins — so they connect by URL,
  not an approve screen. n8n, Zapier, and Make **also expose an MCP server**, so
  their cards additionally offer **"Use as tools"** (two-way — the assistant runs
  your workflows) alongside **"Events webhook"** (one-way). "Use as tools" opens the
  MCP connect flow prefilled (paste your instance URL → OAuth), since these are
  self-hosted/per-account and have no single catalog URL.
- **The other catalog cards** (Slack, GHL, Salesforce, Google Drive/Sheets,
  NetSuite, Calendar, Code repos) now **Connect via the MCP flow** — the card
  opens the "Add MCP server" dialog prefilled with the tool name, so you connect
  it by OAuth or token (paste its MCP URL). Each card declares a `connect` mode
  (`mcp` default, `webhook`, or `soon`). Only **Email** (IMAP/SMTP) and raw
  **Database** remain **"Coming soon"** with a disabled button — they have no
  backend yet, so enabling them would be a dead click.
- Backend: [`IntegrationController`](../app/Http/Controllers/IntegrationController.php),
  [`N8nDispatcher`](../app/Services/N8nDispatcher.php) (generic webhook poster),
  [`DispatchN8nEvent`](../app/Jobs/DispatchN8nEvent.php) (per-provider job),
  `UserIntegration` model.
- **Config (all in `.env`):** `INTEGRATIONS_LIVE`, `INTEGRATION_WEBHOOK_PROVIDERS`
  (comma-separated webhook providers), `INTEGRATION_N8N_TIMEOUT`,
  `INTEGRATION_N8N_SECRET_HEADER`, `MCP_CATALOG_*` — defaults in
  `config/integrations.php`.

## 7. Theming

- **Brand palette: navy + gold** (aligned with cwglobalpeople.com). Light mode is
  white with a navy primary and gold accents; dark mode uses navy-tinted surfaces
  with gold primary CTAs. Brand tokens live in
  [`resources/css/app.css`](../resources/css/app.css) (`--brand-gold`,
  `--brand-navy`, …) and are used across avatars, gradients, and the login screen.
- The product is labelled **CWGP-AIMe** on the login screen (the header brand
  name remains **CW Global People**).
- Light / dark / system mode, with the preference remembered across sessions.
- Built on Tailwind CSS v4 + shadcn-vue design tokens.

## What's NOT built yet

- **Office document uploads** (Word/Excel/CSV) — chat now accepts **images +
  PDFs** natively (§7), but Office formats would need server-side text
  extraction. Also **project-level files** (a persistent per-project knowledge
  base, vs. per-message attachments) and **auto-updating memory** — see
  [roadmap.md](roadmap.md).
- **Streaming** chat responses (replies arrive all at once after a wait).
- Real **Dashboard** content — only placeholder cards.
- A custom brand **logo icon** — still the default Laravel mark; only the text
  was rebranded.
