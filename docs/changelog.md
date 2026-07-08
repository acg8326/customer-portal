# Changelog — what we changed from the base starter kit

This app started as the **Laravel Vue starter kit**. Here's everything we've
customized so far, newest first.

## Navigation moved to a collapsible left sidebar

- **Left sidebar instead of a top header** (Claude-style). `AppLayout` now renders
  [`AppSidebarLayout.vue`](../resources/js/layouts/app/AppSidebarLayout.vue); the
  old header layout is retained but no longer wired up.
- **Open/close:** toggle with the rail button or **⌘/Ctrl-B**. Collapses to an
  icon-only rail (labels become tooltips) and remembers its state across page
  loads via a `sidebar_state` cookie. On mobile it becomes an overlay drawer.
- Rebuilt [`AppSidebar.vue`](../resources/js/components/AppSidebar.vue) with the
  real nav (Dashboard, Chat, Projects, Integrations), carried the **⌘/Ctrl-K chat
  search** over from the header, and dropped the stale starter-kit footer links.
  The user menu is pinned to the bottom.

## Docs: competitive comparison + PDF export

- **[comparison.md](comparison.md)** — why building AiMe in-house beats renting a
  per-seat AI SaaS (e.g. toppfive.net): no per-user fee (all staff use one
  internal deployment), full ownership/customization, data stays on our infra,
  native MCP + n8n integrations, plus an honest feature-by-feature table.
- **`php artisan docs:pdf {source} {--output=}`** — renders any Markdown doc to a
  styled, branded PDF (dompdf + `Str::markdown`). Generated
  [features.pdf](features.pdf); run it on any doc (e.g. `docs/comparison.md`).

## MCP Phase 2 — streaming tool turns

- **MCP turns now stream** token-by-token like every other chat (via
  `beta.messages` `createStream` with `mcpServers`), removing the Phase-1
  non-streaming limitation. The chat routes everything through `/chat/stream`.
- **Live tool indicator:** while a server-side MCP tool runs, the composer shows
  **"Using &lt;server&gt;…"** (a new `tool` SSE frame). Verified live end-to-end
  against a public MCP server (streamed deltas + tool call + final answer).
- Still Phase 3: OAuth for marquee tools (Slack/GitHub one-click) and image/PDF
  passthrough on MCP turns (they remain text-only).

## MCP servers — native tool use (Phase 1)

- **Connect MCP (Model Context Protocol) servers** and the assistant can call
  their tools natively in chat. Add a server (name, URL, optional bearer token)
  on the Integrations page → "MCP servers"; enable/disable and delete per server.
  Per-user, **token encrypted at rest**, URL **SSRF-guarded** (public http(s)
  only). New `mcp_servers` table, `McpServer` model, `McpServerController`.
- **Server-side tool execution.** When any MCP server is enabled, chat routes to
  the tool-capable path ([`ChatController::completeWithMcp`](../app/Http/Controllers/ChatController.php))
  which calls `beta.messages` with `mcpServers` + the `mcp-client` beta; Anthropic
  runs the tool calls server-side and returns the final answer. Verified live
  end-to-end against a public MCP server (Claude called a tool and used the result).
- **Tradeoff:** MCP turns are **non-streaming** (tool use pauses don't stream
  cleanly yet) and send **text-only history** — streaming-with-tools and
  attachment passthrough are Phase 2. Config: `ANTHROPIC_MCP_BETA`.
- This is the foundation for native connectors to Slack, GitHub, Notion, etc.
  (any tool with an MCP server); tools without one still use n8n/webhooks.

## Streaming chat replies

- **Replies now stream token-by-token** over Server-Sent Events. New
  `POST /chat/stream` endpoint ([`ChatController::stream`](../app/Http/Controllers/ChatController.php))
  uses the Anthropic SDK's `createStream`, emitting `meta` / `delta` / `done` /
  `error` SSE frames; the Vue [`ChatPanel`](../resources/js/components/ChatPanel.vue)
  reads the stream with `fetch` + `ReadableStream` and fills the assistant bubble
  live. The "Thinking…" indicator now shows only until the first token arrives.
- **Same bookkeeping as before.** Once the stream finishes, the assistant message
  is persisted, conversation token totals updated, the user's budget charged, and
  the n8n event queued — identical to the non-streaming path. The old
  `POST /chat/message` endpoint stays as a fallback. Shared setup was extracted
  into `startTurn` / `buildHistory` / `systemBlocks` / `finalizeTurn` so both
  paths can't drift. File uploads stream too. Verified live against the API.

## Hardening: rate limits, queued webhooks, error logging

- **Request rate limiting.** Per-user throttles on the sensitive endpoints —
  chat send (`30/min`), chat search (`60/min`), integration connect/disconnect
  (`20/min`), and the n8n test event (`10/min`). Limits are **keyed per-user**
  (so shared networks don't collide), set well above real usage (so customers
  don't hit them in normal use), return a friendly *"you're doing that a bit
  fast"* message with `Retry-After`, and are **`.env`-tunable** (`RATE_LIMIT_*`,
  [`config/ratelimits.php`](../config/ratelimits.php)) — raise instantly, no
  deploy. Complements the token budget (rate = burst/loop guard; budget = cost).
- **n8n dispatch moved to a queued job.** The `chat.completed` webhook now fires
  from [`DispatchN8nEvent`](../app/Jobs/DispatchN8nEvent.php) on the queue with
  retries + backoff, so a slow or failing n8n never adds latency to the reply.
  (The "Send test" button stays synchronous so you see the result immediately.)
- **Structured error logging** around the Claude call — failures now log the
  user, conversation, model, and exception class for debugging (still returns a
  friendly 502 to the user).

## Token budgets, SSRF guard, prompt caching + history trimming

- **Per-user token budget (like Claude's usage limits).** Each user gets a
  rolling allowance (default **1,000,000 tokens / 30 days**, configurable) counted
  from the Claude API `usage`. When it's spent, new messages are blocked with a
  friendly "resets on <date>" message until the window rolls over
  ([`TokenBudget`](../app/Services/TokenBudget.php),
  [`config/usage.php`](../config/usage.php)). The **Dashboard** now shows a live
  usage bar (used / limit, % , reset date) plus conversation/project/skill tiles
  ([`DashboardController`](../app/Http/Controllers/DashboardController.php)).
- **SSRF guard on outbound webhooks.** The n8n webhook URL is validated to be a
  **public http(s) address** — private, loopback, link-local, and cloud-metadata
  ranges (e.g. `169.254.169.254`) are rejected, both when connecting and again at
  send time (DNS-rebinding defense) ([`PublicUrl`](../app/Support/PublicUrl.php),
  [`PublicHttpUrl`](../app/Rules/PublicHttpUrl.php)).
- **Prompt caching + history trimming.** The system prompt is sent with
  `cache_control` so repeat turns re-read it cheaply, and only the most recent
  **N messages** (`ANTHROPIC_HISTORY_LIMIT`, default 40) are replayed to the API
  each turn — bounding context growth and cost on long chats.
- Config: `USAGE_TOKEN_LIMIT`, `USAGE_PERIOD_DAYS`, `USAGE_LIMIT_ENABLED`,
  `ANTHROPIC_HISTORY_LIMIT`. 11 new tests (budget, SSRF, dashboard).

## n8n is now a live connector + setup guides

- **n8n actually connects.** The Integrations page is now controller-backed
  ([`IntegrationController`](../app/Http/Controllers/IntegrationController.php)).
  You paste your n8n **Webhook node Production URL** (+ optional shared secret),
  and AiMe BOT **POSTs a `chat.completed` event** to it after every reply
  ([`N8nDispatcher`](../app/Services/N8nDispatcher.php)). Includes **Send test**
  (fires a `test.ping` and reports the HTTP status) and **Disconnect**.
  Per-user, stored **encrypted** in a new `user_integrations` table. 12 tests.
- **Step-by-step setup guides.** Every card has a **Setup guide** link that opens
  a modal with numbered steps for that tool. Live providers get a **Connect now**
  button in the guide; others stay "Coming soon".
- **Added NetSuite and n8n** to the page — n8n under **Automation**, **NetSuite**
  under a new **ERP & business systems** category (still placeholder).
- Config: `INTEGRATIONS_LIVE` (which providers are wired up),
  `INTEGRATION_N8N_TIMEOUT`, `INTEGRATION_N8N_SECRET_HEADER`
  (`config/integrations.php`). Flash success/error is now shared to Inertia.

## Skills/Projects tweaks

- **Removed the per-project Memory field** (not needed for now): dropped from the
  `ProjectKnowledge` panel, `ProjectController` (validation + props), the system
  prompt, and UI copy. The `conversations`/`projects` columns are retained but
  unused, so it's easy to bring back. Projects keep **Instructions**.
- **Skills tab fixes:** deleting a skill now uses a clear inline **Delete /
  Cancel** confirm (was an easy-to-miss two-click), and starter-library items you
  already have show a disabled **"Added"** badge instead of letting you add
  duplicates.

## Skills (reusable instruction presets)

- **New Settings → Skills tab.** Create per-user **skills** (name, emoji, description,
  instructions), add ready-made ones from a **starter library**
  (`config/skills.php`: Summarizer, Email drafter, RMA evaluator, Translator,
  Meeting notes), or **import a `SKILL.md`** (front-matter parsed). New `skills`
  table, `Skill` model, and `Settings\SkillController` (CRUD and import), all
  owner-checked.
- **Skill picker in chat.** A selector above the composer applies **one skill at a
  time**; its instructions are injected into the system prompt (alongside any
  project instructions). Selection is stored on the conversation
  (`conversations.skill_id`) and restored on reopen. Verified E2E (Translator skill
  → replies in Spanish). 6 skill tests.
- Note: these are **instruction presets**, not executable Anthropic Agent Skills
  (which need a code-execution sandbox this portal doesn't have).

## Chat search, more models, categorized Integrations

- **Chat search.** The header search icon (and **⌘/Ctrl-K**) opens a dialog that
  searches your conversations by **title or message content** and shows a
  snippet; clicking a result opens that chat (project chats open in-project). New
  `GET /chat/search` (user-scoped, LIKE-escaped, 20 results); the target
  `ChatPanel` reads `?c={id}` on mount to open the conversation. 4 search tests.
- **More Claude models.** Expanded the picker from 3 to **8 verified models**
  (Opus 4.8/4.7/4.1, Sonnet 5/4.6/4.5, Haiku 4.5, Fable 5) — each id was probed
  against the API before adding. Still an editable allowlist in
  `config/services.php`.
- **Integrations grouped by category.** The page is now organized into
  **Communication, CRM, Files & documents, Automation, Productivity & data** —
  e.g. Slack under Communication, **GoHighLevel (GHL)** / HubSpot / Salesforce
  under CRM.

## Full-width layouts + Integrations

- **Every main page now uses the full window width** to match the maximized chat:
  Dashboard, Projects (list + workspace), and the new Integrations page all opt
  into the `fullWidth` layout flag (dropping the centered `max-w-7xl` cap). The
  chat conversation + composer also stretch full-width (removed the narrow
  `max-w-3xl` reading column) so there's no dead space left/right.
- **New Integrations page + nav item** (`/integrations`, `Route::inertia`) — a
  placeholder grid of connector cards (Slack, Google Drive, Email, Webhooks,
  Sheets, Calendar, Database, Code repos) marked "Coming soon", styled with the
  navy→gold brand. Added to the top nav (`Plug` icon).
- Projects list now shows up to **4 columns** on wide screens.

## Chat file uploads (images + PDFs)

- **You can now attach images and PDFs to a chat.** A paperclip in the composer
  opens a picker; selected files show as removable chips, and each sent message
  shows its attachments. Claude reads them **natively** (verified end-to-end: a
  red image → "Red").
- **Re-sent every turn:** attachments are stored on their message and rebuilt
  into the request each turn, so follow-up questions keep the file in view. This
  costs tokens per turn — now visible via the token pill.
- **Backend:** `messages.attachments` JSON column; files stored on the private
  disk under `chat-attachments/{conversation}` (deleted with the conversation);
  `ChatController` turns them into image/PDF content blocks. All
  **`.env`-configurable** under `services.anthropic.uploads`
  (`ANTHROPIC_UPLOADS_ENABLED`, `_MAX_FILES`, `_MAX_SIZE_KB`, `_MIMES`).
- Validation (type/size/count) enforced client- and server-side; the chat JSON
  endpoints now render **JSON errors** (added `chat/message` + `chat/conversations/*`
  to `shouldRenderJsonWhen`) so the UI can surface them. 6 upload tests added.

## Token usage, header & dividers

- **Token usage is now tracked and shown.** Each Claude reply's `input`/`output`
  token counts (from the API `usage`) accumulate on the conversation
  (`prompt_tokens` + `completion_tokens` columns) and surface as a small **"N
  tokens"** pill in the chat composer footer (hover for the in/out split). The
  count loads with a conversation and resets on **New chat**.
- **Header is now full-width** (dropped the `max-w-7xl` cap) so the logo/nav sit
  flush-left, aligned with the full-bleed chat sidebar; the active-tab underline
  uses the brand **gold**. Still responsive (mobile sheet menu unchanged).
- **Stronger dividers**: bumped the `--border` token in both themes and added a
  full-width divider above the composer, so the panel's sections read clearly
  (especially on the dark navy surfaces).
- **Verified per-user isolation** with tests: a user only sees their own projects,
  and cannot open, edit (memory included), delete, or attach a chat to another
  user's project (all return 404). Projects/memory are strictly per login account.

## Brand theme (navy + gold)

- **Re-themed the whole app to CW Global People's navy + gold palette** to align
  with cwglobalpeople.com. Reworked the design tokens in
  [`resources/css/app.css`](../resources/css/app.css): light mode uses a **navy
  primary** with gold ring/accents on white; dark mode uses **navy-tinted
  surfaces** with a **gold primary** (gold CTAs pop on navy). Added reusable
  brand tokens (`--brand-gold`, `--brand-gold-light`, `--brand-gold-dark`,
  `--brand-navy`, `--brand-navy-dark`) exposed as `bg-brand-*`/`from-brand-*`
  utilities.
- Swapped every **cyan→indigo** gradient/glow (bot avatars, empty-state glow,
  project cards, avatars) to **navy→gold**, and retimed the login "sonar"
  background + accents from teal (`#2DE2C8`) to gold (`#D4A537`) over navy.
- **Renamed the product label from "Customer Portal" to "CWGP-AIMe"** on the
  login eyebrow (`CW GLOBAL PEOPLE · CWGP-AIMe`) and the auth split layout.
  (App/brand name in the header stays **CW Global People**.)

## Chat layout

- **Maximized the `/chat` page to full width.** The chat now breaks out of the
  app's centered `max-w-7xl` container (via a `fullWidth` layout flag threaded
  through `AppLayout` → `AppHeaderLayout` → `AppContent`), so the conversation
  sidebar hugs the far-left edge and the panel fills the whole window — no more
  left/right white gutters. Added a `fullBleed` prop to `ChatPanel.vue` (drops
  the rounded card border for the standalone page); messages and the composer
  stay in a centered `max-w-3xl` readable column. The Project workspace keeps its
  bordered-card look.

## Projects (Claude-style workspaces)

- Surfaced project **Instructions** and **Memory** as a **visible right-hand
  panel** (`ProjectKnowledge.vue`) in the workspace (was previously hidden behind
  a gear dialog), with a **Delete project** button — and added **delete** on the
  Projects list cards. Much more discoverable.
- Added **Projects**: named workspaces with their own **Instructions**, editable
  **Memory**, and project-scoped chats. New `projects` table/model + `project_id`
  on `conversations`; `ProjectController` (CRUD) + routes; pages
  `projects/Index.vue` (list/create) and `projects/Show.vue` (workspace + a
  settings dialog for name/instructions/memory).
- A project's instructions + memory are **injected into the system prompt** for
  every chat in it. Conversations are scoped (project chats stay out of the
  standalone `/chat`). Added a **Projects** nav item.
- Refactored the chat into a reusable **`ChatPanel.vue`** used by both `/chat`
  and the project workspace (with `brand`/`empty` slots).

## Chat persistence (Projects foundation)

- **Chats are now saved to the database per user.** New `conversations` +
  `messages` tables/models; the chat gained a **conversation sidebar** (new chat,
  history, delete) and chats survive refreshes and reopen with full history.
- The server is now the source of truth: `send` takes a conversation id + the new
  message and loads history from the DB; added `GET/DELETE` conversation
  endpoints, all scoped to the authenticated user.
- This is the **foundation for Projects** (next: projects with instructions +
  editable memory, then files). See [roadmap.md](roadmap.md).

## Tooling / CI

- Made the codebase pass CI checks: fixed PHPStan type errors in
  `ChatController` (typed model list, narrow Claude reply blocks to `TextBlock`),
  gave `composer types:check` a 512M memory limit, made the chat model handler
  type-safe, and updated tests for the new behavior (`/` redirects guests to
  login; removed the obsolete login-rate-limit test since rate limiting was
  disabled).
- Note: GitHub Actions itself was blocked by an **account billing lock** (jobs
  never start) — that's a GitHub-account issue, separate from the code.

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
- (Logo _icon_ is still the default Laravel mark — not yet replaced.)

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
