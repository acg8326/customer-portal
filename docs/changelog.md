# Changelog — what we changed from the base starter kit

This app started as the **Laravel Vue starter kit**. Here's everything we've
customized so far, newest first.

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
