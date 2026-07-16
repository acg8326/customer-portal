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
| Registration                    | ❌ Removed | —                                     | No public sign-up; **admins add users** at `/users` (see below) |
| Forgot / reset password         | ✅ Active  | `/forgot-password`, `/reset-password` | "Forgot password?" link on login          |
| Email verification              | ✅ Active  | `/email/verify`                       | Dashboard & Chat require a verified email |
| Two-factor authentication (2FA) | ✅ Active  | under Settings → Security             | TOTP + recovery codes                     |
| Passkeys (WebAuthn)             | ✅ Active  | under Settings → Security             | Passwordless login option                 |

**Roles & user management (admin-only).** Three roles: **`super_admin`**,
**`admin`**, and **`user`** (a `role` column on `users`). Since there's no
public registration, **admins add members** on the **Users** page (`/users`,
in the sidebar for admins only) — create with name/email/password/role (the
UI only assigns admin/user; super admin is granted by migration/seed), or
remove a user (can't remove yourself; **only the super admin can remove a
super admin**). New accounts are pre-verified so they can sign in immediately.
Enforced by the `admin` middleware
([`EnsureUserIsAdmin`](../app/Http/Middleware/EnsureUserIsAdmin.php)) on the
routes and by `User::isAdmin()` (true for both admin tiers) /
`User::isSuperAdmin()`; non-admins get a 403. The super admin additionally
sees org-wide insights — the dashboard's **Team feedback** and **Team
usage** cards — and can set the org-wide token limit in-app. Seeded:
`alex.gordo@cwglobalpeople.com` (super admin, promoted by a data migration on
deploy too), `dennies.salenga@cwglobalpeople.com` (admin).
Backend: [`UserController`](../app/Http/Controllers/UserController.php).

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
| Dashboard             | `/dashboard`           | ✅ Greeting + usage meter, feedback & suggestions card; super admins get a KPI strip + tabbed insights (team usage / cost / feedback) |
| Chat                  | `/chat`                | ✅ Working — AI chat powered by the Claude API (see §7)  |
| Integrations          | `/integrations`        | ✅ **MCP servers + n8n live**; other cards placeholder    |
| Settings → General    | `/settings/general`    | ✅ Theme, language, message font size (clean row layout) |
| Settings → Profile    | `/settings/profile`    | ✅ Name & email, chat preferences, memory, delete account |
| Settings → Security   | `/settings/security`   | ✅ Change password, manage 2FA & passkeys                |
| Settings → Skills     | `/settings/skills`     | ✅ Create / import / manage reusable instruction presets |

`/` (root) redirects: → `/dashboard` when logged in, → `/login` otherwise.

## 5. Account management (Settings)

- **Search:** a **Search settings** box above the settings nav filters a
  built-in index of every setting (theme, language, passkeys, memory, …) and
  jumps straight to the right page.
- **Shared style:** every tab follows the **General** page's visual language —
  uppercase section labels over rounded bordered cards
  ([`SettingsSection.vue`](../resources/js/components/SettingsSection.vue)).
- **General:** theme (light/dark/system), preferred language, and message font
  size (Small/Medium/Large, browser-stored, scales chat bubbles) — presented as
  clean label-left/control-right rows. `/settings/appearance` redirects here.
- **Profile:** change name and email; chat preferences; assistant memory;
  delete account (requires verified email).
- **Security:** change password; enable/disable two-factor auth (with QR code
  and recovery codes); register and manage passkeys. Viewing this page requires
  re-confirming your password.
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
- **Rich Markdown answers:** assistant replies render as formatted Markdown —
  **tables**, code blocks (with a mono-styled surface + horizontal scroll),
  ordered/unordered lists, headings, blockquotes, bold/italic, and links —
  instead of raw text. So when AiMe returns a channel list or a comparison it
  shows as a real table, not `| pipes |`. Rendered with `marked` (GFM) and
  sanitized with `DOMPurify` before it's inserted (model output is never
  trusted raw); styling follows the light/dark theme tokens. User messages stay
  plain text. See [`ChatPanel.vue`](../resources/js/components/ChatPanel.vue).
- **Personalized empty state:** a time-of-day greeting using the logged-in
  user's name — "Good morning/afternoon/evening, {name}" — with a simple
  "Hi, I'm AiMe BOT. Ask me anything." subtitle.
- **Model switching is live:** changing the model in the header applies to the
  next message (no refresh); you can even switch models mid-conversation.

> Note: the chat follows the app's light/dark theme (Settings → General). The
> dark background is dark mode, not the chat itself.

- **Model (grouped picker, LibreChat-style):** the header's model button opens
  a two-pane menu — providers on the left (hover/tap to preview), that
  provider's models on the right, each with a **"when to use it" hint**
  ("Fastest & cheapest — quick questions"). Providers: **Anthropic (Claude)**
  — the full experience: connected tools, web search, thinking, files,
  memory — plus **OpenAI, Google Gemini, DeepSeek, Groq, Mistral, xAI Grok**
  via their OpenAI-compatible APIs (**plain chat only**; the Web/Thinking/
  attach buttons grey out on them). Each provider is enabled by **one global
  API key** in `.env` (`OPENAI_API_KEY`, `GEMINI_API_KEY`, …) so usage stays
  inside the org budget — no per-user keys. **Locked providers** (no key)
  still show with a 🔒; picking one opens a **"Request access"** dialog that
  sends an `api_request` entry to the super admin's Team feedback card.
  Model lists are validated server-side and overridable per provider
  (`ANTHROPIC_MODELS="id:Label"`, `{PREFIX}_MODELS="id:Label|hint"`). Backed
  by [`ModelCatalog`](../app/Services/ModelCatalog.php) +
  [`OpenAiCompatibleChat`](../app/Services/OpenAiCompatibleChat.php)
  (streaming, token usage recorded to the same budget).
- **Image generation:** the composer's 🖼️ toggle turns your next message into
  an image prompt (OpenAI `gpt-image-1` by default — Claude doesn't generate
  images). The PNG is stored as an assistant attachment, renders inline, and
  charges `IMAGE_TOKEN_COST` tokens (default 5 000) to your budget. Off in
  private chats; without a key the button opens the request-access dialog.
  ([`MediaController`](../app/Http/Controllers/MediaController.php),
  [`OpenAiMedia`](../app/Services/OpenAiMedia.php).)
- **Speech:** a composer 🎤 records and **transcribes dictation** into the
  message box (`SPEECH_STT_MODEL`, audio never stored), and a **Listen**
  button under each AiMe reply reads it aloud (`SPEECH_TTS_MODEL`/voice).
  Both default to `OPENAI_API_KEY` and charge `SPEECH_TOKEN_COST` (default
  500) tokens per request.
- **Workspace default model:** where a new chat starts is resolved as
  **workspace default → `ANTHROPIC_MODEL`**. The **super admin** sets the
  workspace default for everyone from **Dashboard → Team usage → gear**
  (stored in `app_settings` as `chat.default_model`, cleared = back to
  `.env`). A per-chat pick in the header still wins for that browser
  (`localStorage`). Values that drop off the allowlist are skipped
  automatically.
- **Private chat:** the header's **Private** toggle (ghost icon) starts a chat
  that **never touches the database** — no conversation row, no messages, no
  auto-title, no memory extraction, no webhooks. The browser holds the
  transcript and resends it with each turn; it disappears on refresh, on
  toggling private off, or on opening a saved chat. Token usage is still
  charged. Attachments, retry, and connected tools (their approval gate needs
  a conversation row) are off in private mode; web search, extended thinking,
  skills, and MCP servers still work. A gold "not saved" pill marks the mode.
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
  multi-line default lives in `config/services.php`). The default persona is
  written **in the style of Claude's own chat (claude.ai)**: response length
  calibrated to the question, **prose by default** (bullets/headers only for
  genuinely multifaceted content), warm-but-direct tone with no filler and
  **honest pushback** when a premise is wrong, calibrated uncertainty with a
  **grounding rule** (prefer connected tools over general knowledge for
  account/policy specifics, and say when an answer is general rather than
  CW-specific), at most one clarifying question per reply, graceful
  conversational refusals, no engagement-bait endings, and no emojis unless the
  user leads — while still identifying as AiMe BOT. The model
  **allowlist** is
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
- **File uploads (images, PDFs + Office files):** attach files with the
  composer paperclip **or paste an image straight into the composer with
  Ctrl/Cmd+V** (a screenshot or copied image becomes an attachment; plain-text
  pastes are unaffected). Images/PDFs go to Claude natively; **DOCX / XLSX /
  CSV / TXT / MD** are text-extracted server-side at upload
  ([`OfficeTextExtractor`](../app/Services/OfficeTextExtractor.php) — plain
  `ZipArchive` + XML parsing, no heavy libraries, sheets labeled by name,
  capped at `ANTHROPIC_UPLOADS_EXTRACT_MAX_CHARS`) and sent as labeled text
  blocks. Content survives; layout/charts don't. Files are **re-sent every
  turn** (stored on the message), so follow-up questions keep the document in
  view. Configurable in `.env` (`ANTHROPIC_UPLOADS_*`).
- **Automatic memory (like claude.ai):** every `ANTHROPIC_MEMORY_EVERY`
  messages, a cheap background call
  ([`MemoryCurator`](../app/Services/MemoryCurator.php), Haiku by default)
  distills durable facts about the user — role, recurring projects/clients,
  standing preferences — into a memory list injected into the system prompt as
  `## Memory`. Fully transparent: the user sees, **edits, deletes, or wipes**
  every memory under **Settings → Profile → Assistant memory**, and can turn
  automatic memory off entirely (per-user; memories are kept but unused while
  off). Bounded (`ANTHROPIC_MEMORY_MAX_ITEMS`), never stores sensitive topics
  (extraction prompt forbids it), and — like user preferences — can't override
  the safety blocks.
- **Token usage:** each reply's input/output tokens (from the Claude API `usage`)
  accumulate on the conversation and show as a small **"N tokens"** pill in the
  composer footer (hover for the in/out breakdown). Resets on New chat.
- **Per-user token usage (+ optional cap):** usage is tracked per reply over a
  rolling window (`USAGE_PERIOD_DAYS`, default 30) and shown on the **Dashboard**.
  A cap is **optional**: `USAGE_TOKEN_LIMIT=0` (the default) means **unlimited** —
  usage is still counted and displayed, but never blocks. Set a positive
  `USAGE_TOKEN_LIMIT` to enforce a cap (then over-budget sends are blocked with a
  "resets on <date>" message until the window rolls over). Turn tracking off
  entirely with `USAGE_LIMIT_ENABLED=false`.
  ([`TokenBudget`](../app/Services/TokenBudget.php).)
- **Dashboard layout:** the page opens with a time-of-day greeting
  ("Good morning, Alex" + date). Members see their token-usage card and the
  feedback form. Super admins instead get a **KPI strip** — four glanceable
  tiles (your tokens with mini progress bar, team total, est. API spend with
  $-saved-by-caching, 👍/👎 feedback score) — and a single **Organization
  insights** card with segmented tabs (**Team usage / Cost & efficiency /
  Feedback**) so only one detail view is open at a time, instead of five
  stacked full-width cards.
- **Team usage screen + in-app limit settings (super admin):** the Dashboard's
  **Team usage** card lists every member's tokens in their current window
  (heaviest first, with per-user progress bars against the limit) plus the
  **org total**, and a gear opens inline settings to change the **token limit
  per user**, **period days**, and the **workspace default model** for everyone
  at once. UI-set values are stored in the new `app_settings` table
  ([`AppSettings`](../app/Services/AppSettings.php)) and **override the
  `.env` defaults** immediately — no redeploy; clearing falls back to `.env`.
  (`PATCH /dashboard/usage-settings`, super admin only.)
- **Cost & efficiency card (super admin):** estimated API spend in dollars,
  aggregated over all stored conversations. Three tiles — **prompt-cache hit
  rate** (Claude input tokens served from cache), **$ saved by caching**
  (reads bill at ~10% of input price), and uncached input — plus a per-model
  table (model, provider, input/output tokens, est. cost, sorted by cost).
  Prices are config, not code: per-model `[input, output]` USD/MTok in
  `services.llm_pricing` with a single-line override
  `LLM_PRICES="model:input:output,…"`, a `LLM_PRICE_DEFAULT_INPUT/_OUTPUT`
  fallback for unlisted models, and cache multipliers
  (`LLM_CACHE_READ_MULTIPLIER` 0.1 / `LLM_CACHE_WRITE_MULTIPLIER` 1.25).
  Estimates only — deleted chats drop out, and cache columns count from
  deployment day.
- **Prompt caching + history trimming:** two `cache_control` breakpoints on
  **every** Claude path — plain, MCP/web (beta), and the connected-tools loop.
  The first sits on the system block; because the API builds the cache prefix
  tools → system → messages, it also caches the **tool schemas** (the biggest
  static cost with up to `COMPOSIO_MAX_TOOLS` tools). The second is a
  top-level auto-cache marker on the **conversation history** — crucial for
  two reasons: (a) Claude Opus models only cache prefixes ≥ 4,096 tokens, and
  the system prompt alone (~1,800 tokens) is silently below that, so without
  the history in the prefix most chats never cached at all; (b) each turn (and
  each round inside the tool loop) now re-reads the whole prior prompt —
  history *and* tool results — at ~0.1× input price instead of full price.
  Per-turn cache usage is persisted on the conversation
  (`cache_read_tokens` / `cache_write_tokens`) for hit-rate monitoring. Only
  the most recent `ANTHROPIC_HISTORY_LIMIT` messages (default 40) are replayed
  each turn, keeping long conversations' context and cost bounded.
- **Per-toolkit routing (cost):** when several tool sources are connected
  (Slack + NetSuite + …), only the toolkit(s) the conversation **mentions** ship
  their schemas — keyword match over the replayed user turns (the toolkit key
  always matches; lists are `.env`-overridable, e.g. `NETSUITE_KEYWORDS`). No
  match → everything ships; a single source bypasses routing;
  `COMPOSIO_TOOLKIT_ROUTING=false` turns it off.
- **Tool-result caps (cost):** SuiteQL rows are capped at
  `NETSUITE_SUITEQL_MAX_ROWS`, and every tool result fed back to the model is
  hard-capped at `ANTHROPIC_TOOL_RESULT_MAX_CHARS` (default 20k chars) with a
  truncation note telling the model to narrow the query. Tool results are never
  persisted (history is text-only), so they're never replayed across turns.
- **Date + user grounding:** `buildSystemPrompt()` appends the **current date**
  (day-level, so the cache stays valid within a day) and the signed-in **user's
  name** at runtime — the model stops reasoning from its training cutoff, and
  the web-tools note tells it to search for anything recent instead of
  mentioning a "knowledge cutoff".
- **Compact a conversation (like Claude's /compact):** a **Compact** button in
  the chat header (shown once a conversation has a real exchange) asks Claude to
  summarize the transcript so far into a running summary stored on the
  conversation (`conversations.summary` + `summary_through_id`). From then on
  only messages **newer than the summary** are replayed to the API — the summary
  (injected into the system prompt) stands in for everything before it, so long
  chats keep answering without ballooning context or cost. The full transcript
  stays visible; a small "Earlier messages compacted" pill marks it. Running it
  again folds newer messages into the summary. Prompt is configurable
  (`ANTHROPIC_COMPACT_PROMPT`, default in `config/services.php`).
  (`POST /chat/conversations/{id}/compact`.)
- **Auto-compact:** compaction also runs **automatically** (on the queue) once a
  turn's replayed context crosses `ANTHROPIC_AUTO_COMPACT_TOKENS` (default
  100k; 0 disables) — like claude.ai, the user never has to notice degradation.
  ([`ConversationCompactor`](../app/Services/ConversationCompactor.php),
  [`AutoCompactConversation`](../app/Jobs/AutoCompactConversation.php).)
- **Extended thinking (visible thought process):** a **Thinking** toggle in the
  chat header enables adaptive thinking (summarized display) on supported models
  (`ANTHROPIC_THINKING_MODELS`); the thought process streams into a collapsible
  block above the answer and is stored per message (`messages.thinking`). Not
  applied on the connected-tools loop or unsupported models (silently skipped).
- **Auto-generated titles:** after the first exchange a cheap
  `ANTHROPIC_TITLE_MODEL` (default Haiku) call names the conversation (2–5
  words, conversation language); the sidebar updates live. `ANTHROPIC_AUTO_TITLE`
  toggles it; the truncated-first-message title remains the fallback.
- **Continue on cutoff:** default `ANTHROPIC_MAX_TOKENS` is 8192, and when a
  reply still stops at the cap (`stop_reason: max_tokens`) the UI shows a
  **Continue** button that resumes the answer (`ANTHROPIC_CONTINUE_PROMPT`).
- **Retry, edit-and-resend, and feedback:** every last assistant reply has
  **Retry** (regenerates — the server deletes it and replays history), the last
  user message has a **pencil** (edit and resend — replaces the last exchange),
  and every assistant reply has **thumbs up/down** stored on
  `messages.feedback` (`POST /chat/messages/{id}/feedback`). Feedback surfaces
  on the **Dashboard**'s "Team feedback" card — **super admin only**: the
  whole team's thumbs (up/down totals + recent items with user, excerpt, and
  chat) plus the **written feedback & suggestions** below. Everyone else gets
  no card (`DASHBOARD_FEEDBACK_LIMIT`, default 8 recent items).
- **Written feedback & suggestions:** every member's Dashboard has a
  **Feedback & suggestions** card — pick Feedback or Suggestion, write a note
  (max 2000 chars), Send (`POST /feedback`, `feedback_entries` table). Entries
  land on the super admin's Team feedback card with author, type, and time —
  the free-text complement to the thumbs.
- **Share a chat (team-only):** the header's **Share** button creates a
  read-only link any **logged-in** member can open (`/chat/shared/{token}`) —
  never public. Copy it from the dialog; **Stop sharing** invalidates it.
  Owner-only toggle; the shared view has no composer, thinking, or feedback.
- **Language:** Settings → General → **Language** sets your preferred
  language across the portal — AiMe answers in it ("Auto" = match whatever
  language you write). One system-prompt line; the list is configurable
  (`ANTHROPIC_CHAT_LANGUAGES`).
- **Data retention:** off by default (chats are kept forever). Set
  `RETENTION_CHAT_DAYS` to hard-delete idle conversations (+ attachments) via
  the daily `chat:prune` schedule; trashed records purge after
  `RETENTION_TRASH_DAYS` (default 30). `php artisan chat:prune --dry-run`
  previews. Config: [`config/retention.php`](../config/retention.php).
- **Starred chats:** the star icon on any sidebar chat pins it into a
  **Starred** section above **Recents**, like claude.ai
  (`conversations.starred`, `POST /chat/conversations/{id}/star`). Starring
  doesn't bump recency — within each section, chats stay ordered by last
  activity. The Starred section only appears when something is starred.
- **Per-user chat preferences:** a "Chat preferences" box under **Settings →
  Profile** (`users.chat_preferences`, max 2000 chars) is appended to the system
  prompt as `## User preferences` — standing instructions like "always answer in
  Tagalog" or "be terse". A guard line scopes them to tone/format only (they
  can't override safety rules).
- **Web citations:** web-search citation metadata is collected on every path and
  appended to the answer as a **Sources:** footer of Markdown links, so the
  sources the model cited aren't dropped.
- **Web search + fetch (Claude's native tools).** With `ANTHROPIC_WEB_TOOLS=true`
  (default) the assistant can **search the web** and **read a URL** using
  Anthropic's server-side `web_search` + `web_fetch` tools — no scraping infra on
  our side. A **Web toggle** in the chat header (like claude.ai's) turns it off
  per session — answers then use only the model + connected tools, and the web
  tools' schema/prompt tokens are saved. Active on **every** chat path — plain, MCP, and the connected-tools
  (Composio/NetSuite) loop, so a user with Slack/NetSuite connected keeps web
  access too (the connected-tools loop runs on the beta endpoint and merges the
  web tools alongside the custom tools). If the tools error, the turn falls back
  to a plain answer with a note. So the model doesn't wrongly claim it can't
  browse, a short **web-access note** is appended to the system prompt while web
  tools are on. Configurable: `ANTHROPIC_WEB_TOOLS`, `ANTHROPIC_WEB_TOOL_MAX_USES`,
  `ANTHROPIC_WEB_FETCH_BETA` (web fetch is beta), `ANTHROPIC_WEB_TOOLS_PROMPT`.
- **Export an answer (Markdown / PDF / Word / CSV / XLSX).** Each finished
  assistant answer has a small action row: **Copy**, **.md** (client-side
  download), **PDF** (server-side — Markdown → sanitized HTML via CommonMark →
  dompdf), **Word** (.docx — Markdown → minimal OOXML document via `ZipArchive`,
  with headings, lists, tables, code, and inline bold/italic/code), and, when the
  answer contains a table, **CSV** + **XLSX**. The spreadsheet export parses the
  answer's GFM tables server-side; XLSX and DOCX are both written as minimal OOXML
  via `ZipArchive` (no PhpSpreadsheet/PhpWord, so no `ext-gd` needed). Claude can't
  generate images — it reads them (§7) but there's no image-generation model wired
  in. ([`ChatExportService`](../app/Services/ChatExportService.php),
  [`ChatExportController`](../app/Http/Controllers/ChatExportController.php);
  `POST /chat/export/pdf`, `POST /chat/export/docx`, `POST /chat/export/sheet`.)
  A **downloadable-answers
  note** is appended to the system prompt so that, when asked for a document or
  spreadsheet, the model writes exportable content (Markdown headings / pipe
  tables) and points the user at these buttons instead of refusing. Override the
  note with `ANTHROPIC_FILES_PROMPT`.
- **Streaming replies:** the assistant's reply **streams in token-by-token**
  over Server-Sent Events (`POST /chat/stream`) — a "Thinking…" indicator shows
  only until the first token, then the text fills in live. The message, token
  totals, and n8n event are all persisted server-side once the stream finishes
  (identical bookkeeping to the non-streaming path, which remains at
  `POST /chat/message`). File uploads stream too.
- **Live tool activity:** while the assistant works with tools, the typing
  indicator narrates each phase instead of a generic spinner — *"Choosing
  the right tool…"* (first model round) → *"Querying NetSuite (SuiteQL)…"*
  / *"Slack · send message…"* (each call as it executes; Composio names
  humanized via `ChatController::toolActivityLabel()`) → *"Analyzing the
  results…"* (every follow-up round). `tool` SSE events drive it; covered
  paths: the connected-tools loop (NetSuite/Composio, including after an
  approval decision) and the streamed web-search (*"Searching the web…"*)
  / MCP (*"Using {server}…"*) path. Clears when the answer starts.

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
- **Project files (knowledge base):** upload documents to the panel's
  **Files** section (`ANTHROPIC_PROJECT_MIMES`, default docx/xlsx/csv/txt/md —
  text-extractable formats only, since their content is **injected into the
  system prompt** of every chat in the project as a `## Project files` block;
  images/PDFs stay per-message attachments). Extracted once at upload (same
  [`OfficeTextExtractor`](../app/Services/OfficeTextExtractor.php) as chat
  uploads, virus-scanned when scanning is on, unreadable files rejected).
  Bounded: `ANTHROPIC_PROJECT_MAX_FILES` (10) per project and
  `ANTHROPIC_PROJECT_MAX_CHARS` (100k) total per prompt — files over the
  budget are listed by name so the model can say what's missing. Owner-only
  add/remove; deleting the project removes its files from storage.
- **Scoping:** a conversation optionally belongs to a project (`conversations.
project_id`). Project chats appear only in that project; standalone `/chat`
  shows only non-project chats. All endpoints are owner-checked.
- Backend: [`ProjectController`](../app/Http/Controllers/ProjectController.php),
  `Project` model, `projects` table. UI:
  [`projects/Index.vue`](../resources/js/pages/projects/Index.vue),
  [`projects/Show.vue`](../resources/js/pages/projects/Show.vue).

## 8. Integrations

Connect AiMe BOT to outside tools. The page (`/integrations`) groups connectors
**by category** (Communication, CRM, Developer tools, Files & documents,
Automation, ERP & business systems, Productivity & data). Each card has a
**Setup guide** modal rendered as **step cards**: a numbered title header,
menu-path breadcrumb chips (Setup → Company → …), green tick checklists for
boxes to enable, a paste-exactly code block, and info/warning callouts
(`GuideStep` in [`Integrations.vue`](../resources/js/pages/Integrations.vue)).

- **"Currently connected apps" overview.** A summary **table** near the top lists
  every app the user has linked through a card — its category, how it's connected
  (**Per-user (Composio)** or **Events webhook**), the endpoint (for webhooks),
  and quick **Reconnect / Send test / Disconnect** actions. Once an app is
  connected it **moves into this table and drops out of the card grid below** (no
  duplicate card). It only appears once at least one app is connected, and
  doesn't duplicate the MCP-servers section (which manages those separately).
  Table scrolls horizontally on narrow screens.
- **Search.** A search box filters the catalog live by app **name, description,
  or category**; categories with no match are hidden, with a "no matches" note
  when nothing fits. The connected overview above stays put as a summary.

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
- **One-click app cards via Composio (per-user).** *(Deep-dive + add/debug
  playbook: [composio-integrations.md](composio-integrations.md).)* Each app is a
  **category card** with a single **Connect** button, brokered through **Composio**, a
  hosted tool gateway. Composio owns the OAuth apps, so there's **no per-app
  client id/secret** — one `COMPOSIO_API_KEY` plus a per-toolkit auth-config id
  (`config/services.php` → `services.composio`, all `.env`-driven). Connections
  are **per user**: each person clicks **Connect**, authorizes their *own*
  account, and AiMe uses that grant when running the tool — so the assistant
  acts as that user. The **Connect callback verifies the grant is ACTIVE** with
  Composio before showing "Connected". **Slack, GitHub, HubSpot, and Airtable** are wired;
  adding another (Notion, Linear, Google Drive, …) is just another
  `services.composio.toolkits` entry + a card `composio:` key.
  - **How tools run:** Composio's MCP endpoint requires an `x-api-key` header
    that Anthropic's server-side MCP connector can't send, so AiMe runs the tool
    loop itself — it fetches Composio's tool schemas, gives them to Claude as
    normal tools, and executes each call **server-side** via Composio's REST API
    (with the API key + the user's id), looping until Claude is done. Capped by
    `COMPOSIO_MAX_TOOLS` / `COMPOSIO_MAX_TOOL_ROUNDS`. The destructive-action
    guardrail still applies.
  - **Trade-off:** tool traffic flows through Composio, so keep highly sensitive
    tools on the direct flow (Advanced, below) instead.
  - Backend: [`ComposioController`](../app/Http/Controllers/ComposioController.php),
    [`ComposioService`](../app/Services/ComposioService.php), `ComposioConnection`
    model; tool loop in [`ChatController`](../app/Http/Controllers/ChatController.php).
  - *(The older `mcp_catalog` one-click OAuth catalog was removed from the UI —
    most vendors can't self-register an OAuth app, so those buttons failed. The
    catalog config + `catalogConnect` route remain but are no longer surfaced.)*
- **Cross-app interop.** Because every enabled MCP server's tools are offered to
  the model **together** in one turn, the assistant can move/compare data across
  connected apps — e.g. "compare HubSpot deals to the Airtable `Deals` table and
  add the missing ones." No extra wiring; connect both and ask.
- **Hard approval gate (destructive tool calls).** In the connected-tools loop
  (Composio + NetSuite), a tool call whose name contains a destructive verb
  (`ANTHROPIC_TOOL_GATE_VERBS`: create/update/delete/send/…) **pauses the turn
  before executing**: the loop state is persisted encrypted on
  `conversations.pending_tool_state` and the chat shows an **Approve & run /
  Cancel** card listing each call with its exact input. Nothing runs until
  Approve; Cancel finalizes the turn with a note and runs nothing; the pending
  state is consumed exactly once (no double-run), and a new message supersedes
  it. Reads (get/list/search/suiteql) never gate. Toggle with
  `ANTHROPIC_TOOL_HARD_GATE` (default on).
  (`POST /chat/conversations/{id}/tools/decision`.)
- **Destructive-action guardrail (ask-in-text).** For **MCP servers** — which
  Anthropic executes server-side and therefore can't be gated client-side — a
  policy is appended to the system prompt requiring the assistant to **confirm
  before any create / update / delete / send** in text first; reads and
  searches are unrestricted. Toggle with `ANTHROPIC_TOOL_SAFETY`, override the
  text with `ANTHROPIC_TOOL_SAFETY_PROMPT`. When the hard gate is on it
  replaces this for Composio/NetSuite (no double prompt); pair MCP with
  **least-privilege OAuth scopes** (`MCP_OAUTH_SCOPES`) or read-only tokens.
- **Prompt-injection defense (untrusted content).** Whenever **any** tools are
  active (web, MCP, Composio, NetSuite) a second, always-on note is appended:
  content returned by tools, web pages, or files is **data, not instructions** —
  embedded commands ("ignore previous instructions", "send this to…") must not be
  followed; only the user in the chat gives instructions. It also asks the
  assistant to say briefly what it's about to do before each tool call. Unlike
  the destructive-action guardrail, this is **not** skipped by auto-approve.
  Override with `ANTHROPIC_TOOL_USE_PROMPT`.
- **Model-list validation.** `php artisan chat:check-models` verifies every id in
  the picker against the live API via the free `count_tokens` endpoint (no tokens
  billed), so a stale id can't 404 on users mid-chat. Run it after editing
  `anthropic.models` or as a deploy step.
- **Auto-approve toggle (per session).** For power users, the chat header shows an
  **Auto-approve** switch (only when tools are connected) — a labelled on/off
  toggle rather than a button whose label flipped. When on, the destructive-action
  guardrail above is **omitted** for that conversation so the assistant acts
  without asking each time. Turning it **on** first pops a **confirmation dialog**
  ("Auto-approve tool actions this session?") — the state only flips once the user
  confirms; turning it off is immediate. Default is off (safe); the choice is
  remembered in the browser and sent with each message (`conversations.auto_approve`,
  set per turn). It has no effect if `ANTHROPIC_TOOL_SAFETY=false` (nothing to skip)
  or when no tools are connected.
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
- **Advanced — connect a server directly.** A de-emphasized section at the bottom
  keeps the raw **"Add MCP server"** flow (one-click OAuth or a token, by URL) for
  self-hosted or sensitive tools you don't want routed through Composio. Same
  backend as the MCP servers above.
- **NetSuite (native — NOT Composio).** Composio's NetSuite toolkit's tokens
  401 (`INVALID_LOGIN`) on record reads, so NetSuite is a **native
  integration** against **SuiteTalk REST + SuiteQL**. Auth is **OAuth 2.0
  (Authorization Code Grant)**: paste your integration record's **Client ID /
  Secret** + Account ID; you're redirected to NetSuite to approve, then back
  to `https://aime.cwglobal.ai/integrations/netsuite/callback` (the record's
  Redirect URI must match exactly; derived from `APP_URL` — the guide and
  Connect dialog display the server's actual configured URI, highlighted, so
  what users paste always matches). We store the access + refresh tokens and
  **auto-refresh** before expiry. The in-app setup guide is rendered as
  **styled step cards** (title, NetSuite menu-path chips, tick checklists,
  paste-exactly code block, shown-only-once warning callout) and walks
  through Enable Features (Client/Server SuiteScript, REST Web Services,
  OAuth 2.0) → the integration record as **one card for one screen** (name,
  Authorization Code Grant, scopes REST Web Services **and RESTlets**, and
  the Redirect URI) → save + copy credentials → Account ID → role
  permissions → connect.
  *Legacy:* Token-Based Auth connections made before the OAuth2-only switch
  keep working server-side; the UI no longer offers TBA.

  **Multiple NetSuite accounts (feature-flagged, off by default).** With
  `NETSUITE_MULTI_ACCOUNT=true` a user can connect **several NetSuite
  accounts at once** (one connection per account id; reconnecting an account
  updates it in place). With the flag off (default) the portal behaves like a
  single-account setup: connecting a different account id **replaces** the
  existing connection, the connect dialog hides the Label field, the card
  moves to the connected table as before, and the chat never shows an
  account picker. The schema and server-side scoping stay in place either
  way, so enabling later is just the flag — no migration or redeploy logic. Each connection takes an optional **label** ("Client
  A", "Sandbox") and one is the **default**. The NetSuite card stays in the
  grid after connecting ("Add account"); the *Currently connected* table shows
  **one row per account** with label · account id · default marker, plus
  per-row actions: make-default (star), reconnect, disconnect (deleting the
  default promotes the oldest remaining account). In chat, an **account
  picker** appears in the composer **only when >1 account is connected**; the
  choice is **pinned on the conversation** (`netsuite_connection_id`,
  restored when reopening the chat) and scoping is **server-side**: the tool
  loop is handed exactly one connection, so the model can never pick the
  wrong account. Provenance is visible end-to-end — the tool descriptions
  name the pinned account (so answers cite it), and the live activity
  indicator shows *"Querying NetSuite (SuiteQL) · Client B"* for labelled
  accounts. With one account (or none pinned) everything falls back to the
  default connection, exactly as before.

  `auth_type` on the connection records which method is in use; all secrets and
  tokens are stored **encrypted**
  ([`NetsuiteConnection`](../app/Models/NetsuiteConnection.php)). In chat AiMe
  gets two tools — **`netsuite_suiteql`** (read-only SuiteQL) and
  **`netsuite_get_record`** — executed server-side by
  [`NetsuiteService`](../app/Services/NetsuiteService.php) (TBA signs each
  request; OAuth2 sends a Bearer token), sharing the client-side tool loop with
  Composio (dispatched by the `netsuite_` name prefix). **Either method needs the
  token/role to have REST Web Services + the record permissions.** Config:
  `services.netsuite` (`NETSUITE_ENABLED`, `NETSUITE_TIMEOUT`,
  `NETSUITE_SUITEQL_MAX_ROWS`, `NETSUITE_REST_DOMAIN`, plus OAuth2
  `NETSUITE_APP_DOMAIN` / `NETSUITE_OAUTH_SCOPES` / `NETSUITE_OAUTH_REDIRECT` /
  `NETSUITE_OAUTH_REFRESH_LEEWAY`). Backend:
  [`NetsuiteController`](../app/Http/Controllers/NetsuiteController.php).
- **Cards not yet wired** (GHL, Salesforce, Google Drive/Sheets, Calendar,
  Database, Email) show **"Coming soon"** with a disabled button. Each card
  declares how it connects: a `composio` toolkit key (one-click), `webhook`
  (automation), or nothing → "coming soon". Lighting one up is a config +
  `composio:` key change, no new code.
- Backend: [`IntegrationController`](../app/Http/Controllers/IntegrationController.php),
  [`N8nDispatcher`](../app/Services/N8nDispatcher.php) (generic webhook poster),
  [`DispatchN8nEvent`](../app/Jobs/DispatchN8nEvent.php) (per-provider job),
  `UserIntegration` model.
- **Config (all in `.env`):** `INTEGRATIONS_LIVE`, `INTEGRATION_WEBHOOK_PROVIDERS`
  (comma-separated webhook providers), `INTEGRATION_N8N_TIMEOUT`,
  `INTEGRATION_N8N_SECRET_HEADER`, `MCP_CATALOG_*` — defaults in
  `config/integrations.php`. Composio: `COMPOSIO_API_KEY`, `COMPOSIO_BASE_URL`,
  and per-toolkit `COMPOSIO_<TOOL>_AUTH_CONFIG` / `COMPOSIO_<TOOL>_MCP_SERVER_ID`
  — defaults in `config/services.php` (`services.composio`). NetSuite (native
  OAuth 2.0): `NETSUITE_ENABLED`, `NETSUITE_TIMEOUT`, `NETSUITE_SUITEQL_MAX_ROWS`,
  `NETSUITE_REST_DOMAIN`, `NETSUITE_OAUTH_*` (`services.netsuite`).

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

- **Token-by-token streaming during connected-tools turns** — plain and MCP
  chats stream live, but when the Composio/NetSuite tool loop runs, the final
  answer arrives as one block after the tools finish.
- A custom brand **logo icon** — still the default Laravel mark; only the text
  was rebranded.
