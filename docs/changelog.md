# Changelog — what we changed from the base starter kit

This app started as the **Laravel Vue starter kit**. Here's everything we've
customized so far, newest first.

## Live tool activity in chat

- While the assistant works, the typing indicator now says **what** it's
  doing instead of a generic "AiMe is thinking…" — *"Querying NetSuite
  (SuiteQL)…"*, *"Fetching a NetSuite record…"*, *"Searching the web…"*,
  *"Slack · send message…"* (Composio tool names humanized), *"Using
  {server}…"* for MCP servers.
- The tool loop narrates **every phase**, not just execution: *"Choosing
  the right tool…"* during the first model round, the tool's own label
  while it runs, *"Analyzing the results…"* during each follow-up round —
  so slow (non-streamed) model rounds never fall back to the generic
  indicator mid-turn.
- **Prod delivery fix:** status frames are ~60 bytes, and proxies/CDNs
  that buffer by byte count (anything `X-Accel-Buffering` doesn't cover)
  held them until the answer text flushed everything at once — working
  locally, invisible in prod. Each `tool` frame is now followed by an
  ignored SSE comment pad (`CHAT_SSE_PADDING`, default 4096 bytes, 0 =
  off) and the stream responses send `Cache-Control: no-cache,
  no-transform`.
- Backend: the connected-tools loop (`completeWithClientTools` /
  `applyToolResults`) takes an `onActivity` callback and emits a `tool` SSE
  event with a friendly `label` before each call executes — wired in both
  the streaming turn and the post-approval resume (`toolDecision`). The
  streamed path also surfaces Claude's server-side web search/fetch blocks.
  Labels come from `ChatController::toolActivityLabel()` (unit-tested).

## NetSuite: OAuth 2.0 only + card-style setup guides everywhere

- **The connect dialog is OAuth 2.0 only** — the TBA/OAuth toggle is gone.
  Paste Account ID + Client ID/Secret → approve on NetSuite → connected.
  Legacy TBA connections keep working server-side (signing code retained);
  the UI just no longer offers TBA for new connections.
- **Every setup guide is now step cards** (all integrations, not just
  NetSuite): each step is a titled card with menu-path chips
  (Setup → Company → …), green tick checklists for the boxes to enable, a
  paste-exactly code block, plain info notes, and amber warning callouts.
  The old plain numbered one-liners and the method-tabs machinery were
  removed (`GuideStep` replaces `steps: string[]`).
- **NetSuite guide** steps in order: Enable Features (Client SuiteScript,
  Server SuiteScript, REST Web Services, OAuth 2.0) → integration record —
  one card for one screen: name, Authorization Code Grant, scopes REST Web
  Services **and RESTlets**, and the Redirect URI → save + copy the Client
  ID/Secret immediately (amber shown-only-once warning) → Account ID → role
  permissions → Connect. [docs/netsuite.md](netsuite.md) rewritten to match,
  including a redirect-URI-mismatch troubleshooting row.
- **The guide + Connect dialog show the server's real redirect URI** (from
  `NetsuiteService::redirectUri()`, passed as a page prop) instead of the
  browser origin — on production that's
  `https://aime.cwglobal.ai/integrations/netsuite/callback` — with
  “Redirect URI” and the URL visually highlighted in the dialog.

## Image generation + speech (dictation & read-aloud)

- **Image generation** (🖼️ button in the composer): toggle Image mode, type a
  prompt, and AiMe generates a picture (`POST /chat/image`, OpenAI
  `gpt-image-1` by default). The prompt is saved as your message and the PNG
  as an assistant attachment — it renders **inline in the chat** and lives in
  history like any other message. Off in private chats (images must be
  stored). Each image charges `IMAGE_TOKEN_COST` (default 5 000) tokens to
  the user's budget.
- **Dictation** (🎤 in the composer): record, stop, and the audio is
  transcribed into the message box (`POST /chat/transcribe`, default
  `gpt-4o-transcribe`). The audio is never stored.
- **Read aloud** ("Listen" under every AiMe reply): the reply is spoken via
  TTS (`POST /chat/speech`, default `gpt-4o-mini-tts`). Each speech request
  charges `SPEECH_TOKEN_COST` (default 500) tokens.
- **Keys:** both features default to `OPENAI_API_KEY` (separate
  `IMAGE_API_KEY`/`SPEECH_API_KEY` overrides exist). Without a key the
  buttons show the same **"request access from your admin"** dialog as
  locked chat providers.
- **Inline images generally:** uploaded image attachments now also render
  inline in chat bubbles (owner-only serving route `chat/images/{message}/{i}`).
- New: [`OpenAiMedia`](../app/Services/OpenAiMedia.php),
  [`MediaController`](../app/Http/Controllers/MediaController.php).

## Multi-provider model picker (LibreChat-style)

- **Grouped picker:** the chat header's model button now opens a two-pane
  menu — providers left, models right, each model with a "when to use it"
  hint. Claude stays first and full-featured; **OpenAI, Google Gemini,
  DeepSeek, Groq, Mistral, and xAI Grok** join via their OpenAI-compatible
  APIs with **plain chat only** (tools, web, thinking, attachments grey out —
  they're Claude features).
- **Global keys, not per-user:** one `.env` key per provider
  (`OPENAI_API_KEY`, `GEMINI_API_KEY`, `DEEPSEEK_API_KEY`, `GROQ_API_KEY`,
  `MISTRAL_API_KEY`, `XAI_API_KEY`). All usage is streamed through the server
  and **charged to the same per-user token budget**.
- **Request access:** providers without a key show locked (🔒); picking one
  of their models opens a dialog that sends an **API request** to the super
  admin's Team feedback card (new `api_request` feedback type, key icon) —
  so enabling a provider is a deliberate admin decision.
- Model lists per provider are `.env`-overridable:
  `{PREFIX}_MODELS="id:Label|hint,id:Label|hint"`. Server-side validation
  rejects models from providers that aren't enabled.
- New: [`ModelCatalog`](../app/Services/ModelCatalog.php),
  [`OpenAiCompatibleChat`](../app/Services/OpenAiCompatibleChat.php) (SSE
  streaming client). The old flat model dropdown is gone.

## Feedback & suggestions, settings search + unified settings style

- **Written feedback & suggestions:** a **Feedback & suggestions** card on
  every member's Dashboard (type + message, `POST /feedback`, new
  `feedback_entries` table). Entries appear on the super admin's feedback
  card — renamed **Team feedback** — under the thumbs list, with author,
  type (💡 suggestion / feedback), and time. Complements the thumbs up/down,
  which stay.
- **Dashboard cleanup:** the Conversations / Projects / Skills stat tiles are
  gone (the sidebar already covers navigation; the numbers weren't actionable).
- **Settings search:** a "Search settings" box above the settings nav filters
  an index of every setting and jumps to its page — LibreChat-style.
- **Unified settings style:** Profile, Security, and Skills now follow the
  General page's visual language — uppercase section labels over rounded
  bordered cards (new `SettingsSection.vue`): Account / Chat preferences /
  Assistant memory / Danger zone, Password / Two-factor / Passkeys.
- **Layout polish:** settings content now uses the full available width (the
  old `max-w-xl` cap left most of the page empty); the chat header toolbar
  wraps and collapses to icon-only buttons below the `sm` breakpoint, so
  Private/Web/Thinking/model no longer cram on phones.

## Private chats, General settings page + workspace default model

- **Private chat** (chat header's **Private** toggle, ghost icon): nothing
  touches the database — no conversation, no messages, no title, no memory
  extraction, no webhooks. The browser holds the transcript and resends it
  each turn (`private` + `history` params); it disappears on refresh, toggle,
  or opening a saved chat. Token usage is still charged (usage is real).
  Attachments, retry, and connected tools (Composio/NetSuite — their approval
  gate needs a conversation row) are off in private mode; web search, thinking,
  skills, and MCP still work. Gold "not saved" pill marks the mode.
- **Settings → General** (new page, LibreChat-style clean rows): **Theme**
  (moved from the old Appearance page — old URL redirects), **Language**
  (moved from Profile), and a new **Message font size** (Small/Medium/Large,
  stored in the browser, applied to chat bubbles — markdown headings scale
  with it). Settings nav order: General, Profile, Security, Skills.
- **Workspace default model (super admin):** the Dashboard's Team-usage gear
  now also sets the **default model for everyone's new chats**, stored in
  `app_settings` (`chat.default_model`) — live, no redeploy; "Server default"
  clears it back to `.env` `ANTHROPIC_MODEL`. Ids that fall off the allowlist
  are skipped, so retiring a model can't strand anyone. No per-account setting
  on purpose — the chat header picker already covers personal choice.
- **Model allowlist via `.env`:** the picker list is now overridable in one
  line — `ANTHROPIC_MODELS="id:Label,id:Label"` (default list unchanged in
  `config/services.php`). Claude models only, on purpose: budgets, the
  approval gate, NetSuite tools, and memory are all built on the Claude API.

## Chat sharing, retention policy, reply language + migration consolidation

- **Chat sharing (team-only).** A **Share** button in the chat header creates
  a read-only link (`/chat/shared/{token}`) any **logged-in** member can open
  — never public. The dialog shows the link with Copy; **Stop sharing**
  invalidates it (`conversations.share_token`, owner-only toggle). The shared
  page renders the exchange (Markdown, attachment names) with no composer,
  thinking, or feedback.
- **Data retention policy** (new `config/retention.php`): `chat:prune` runs
  daily (03:30) — with `RETENTION_CHAT_DAYS` set it permanently deletes
  conversations (messages + stored attachments) idle longer than that;
  **off by default** (0 = keep forever). Independently, trashed
  conversations/projects/skills purge for good after `RETENTION_TRASH_DAYS`
  (default 30). `--dry-run` reports without deleting. This resolves the
  roadmap's open privacy/retention decision.
- **Reply language setting** (Settings → Profile): a dropdown sets the
  language AiMe answers in ("Auto" = match the user's message, the default).
  Injected as one system-prompt line; list configurable via
  `ANTHROPIC_CHAT_LANGUAGES`.
- **Migration consolidation.** All 20 "add column" migrations were folded
  into their create-table migrations — 34 files down to 16 clean creates
  (+1 data migration). Schema validated by the full test suite on a fresh
  database.
  **⚠️ One-time deploy note:** the prod migrations table references the old
  file names, so the NEXT deploy must run `php artisan migrate:fresh --force
  --seed` (wipes data — fine while not yet in production use) instead of the
  usual `migrate`. After that, `deploy.sh` works as normal.

## Project files (knowledge base) + comparison.md refresh

- **Project files:** the project panel's new **Files** section holds a
  per-project knowledge base (docx/xlsx/csv/txt/md — text-extractable formats
  only). Content is extracted once at upload (same `OfficeTextExtractor`,
  virus-scanned when scanning is on, unreadable files rejected) and injected
  into the system prompt of every chat in the project as `## Project files`.
  Bounded by `ANTHROPIC_PROJECT_MAX_FILES` (10) and
  `ANTHROPIC_PROJECT_MAX_CHARS` (100k total per prompt — over-budget files are
  listed by name so the model can say what's missing). Owner-only; deleting
  the project cleans up storage. This closes the last big roadmap item.
- **comparison.md refreshed** (was written 2026-07-07): document processing
  marked shipped, native NetSuite/Composio added to the integrations row,
  three-tier roles + live limit settings noted, hard gate + exports called
  out, honest-take paragraph updated.

## Team usage dashboard, in-app usage limits + super admin nav fix

- **Fix: Users nav hidden for the super admin.** The sidebar checked
  `role === 'admin'` literally, so the promotion to `super_admin` hid the
  Users item (the backend still allowed access — only the menu vanished).
  Now both admin tiers see it, matching `User::isAdmin()`.
- **Team usage card (super admin):** every member's tokens in their current
  window, heaviest first, with progress bars against the limit, reset dates,
  and the **org total**.
- **In-app usage settings:** a gear on the card edits the **per-user token
  limit** and **period days** for the whole org at once. Stored in the new
  `app_settings` key-value table ([`AppSettings`](../app/Services/AppSettings.php),
  cached as one map, invalidated on write); `TokenBudget` reads the override
  first and falls back to `.env` — so limits change without a redeploy, and
  clearing a setting restores the `.env` value.
  (`PATCH /dashboard/usage-settings`, super admin only, validated 0–1B / 1–365.)

## Prompt accuracy: real company description + Word in export list

- **Company context now describes the real business** (from
  cwglobalpeople.com): CWGP is a recruitment & staffing company (sourcing,
  vetting, onboarding, payroll for client businesses), and the portal is the
  INTERNAL company-wide assistant — the block keeps the explicit "don't assume
  HR" instruction. So AiMe can answer "what does our company do?" correctly.
- **files_prompt now lists Word (.docx)** — it still described the export
  buttons as Copy/Markdown/PDF/CSV/XLSX from before DOCX export shipped, so
  AiMe never mentioned Word when describing its own capabilities.
  prompts.md synced (PDF regenerated locally).

## Office file understanding, automatic memory + company-context prompt

- **Office file understanding.** Chat uploads now accept **DOCX / XLSX / CSV /
  TXT / MD** alongside images and PDFs. Text is extracted server-side ONCE at
  upload ([`OfficeTextExtractor`](../app/Services/OfficeTextExtractor.php) —
  plain `ZipArchive` + XML, no parsing libraries, mirroring the export
  writers; XXE-safe, sheets labeled by name, sidecar-cached, capped via
  `ANTHROPIC_UPLOADS_EXTRACT_MAX_CHARS`) and sent as labeled text blocks.
- **Automatic memory (like claude.ai).** Every `ANTHROPIC_MEMORY_EVERY`
  messages a background Haiku call
  ([`MemoryCurator`](../app/Services/MemoryCurator.php) + `UpdateUserMemory`
  job) revises a short list of durable facts about the user, injected into the
  system prompt as `## Memory` with the same can't-override-safety guard line
  as preferences. Fully transparent: **Settings → Profile → Assistant memory**
  lists every item with edit/delete/"Forget everything" and a per-user off
  switch (off = stop learning AND stop injecting; items kept). The extraction
  prompt forbids sensitive topics and secrets; list bounded
  (`ANTHROPIC_MEMORY_MAX_ITEMS`); extraction cost charged to the user budget.
- **Company-context prompt block.** AiMe framed everything as HR because the
  prompt only gave the company NAME ("CW Global People") and the model
  inferred the rest. New `## About this portal` block
  (`ANTHROPIC_COMPANY_CONTEXT`) states it's a company-wide assistant (finance/
  NetSuite, ops, sales, docs, web) — not HR-only. prompts.md updated (+ PDF
  regenerated) with the two new blocks and the memory extraction prompt.

## Docs: prompts.md — the complete prompt reference (+ PDF)

- **New [prompts.md](prompts.md):** every prompt AiMe is given, verbatim —
  the persona, web access, downloadable answers, tool narration + injection
  defense, and the tool-safety guardrail (with the exact conditions for when
  each block ships, including the hard-gate/MCP/auto-approve interplay), the
  dynamic blocks built in code (date/user, summary, project, skill, user
  preferences + guard line), and the task prompts (auto-title, compaction,
  Continue). Ends with the prompt-adjacent `.env` table and the caching
  rationale for the block order. `prompts.pdf` generated for review
  (`php artisan docs:pdf docs/prompts.md`); indexed in both READMEs.

## Docs refresh: stale pages corrected + features.pdf regenerated

- **getting-started.md** now leads with **PostgreSQL** (what production runs;
  MySQL/MariaDB/SQLite still documented as working) and lists all three seeded
  logins with roles — it still claimed MySQL-only and a single seeded account.
- **tech-stack.md**: database row corrected (PostgreSQL), project layout tree
  updated to the real codebase (models, services, configs), and the layout
  section fixed — the app uses the **sidebar** layout, not the top header.
- **roadmap.md**: streaming marked ✅ shipped (SSE, thinking deltas,
  citations), with the connected-tools single-block edge noted.
- **features.pdf** regenerated from the current features.md via
  `php artisan docs:pdf` (comparison.pdf intentionally left as-is).

## Super admin role + Starred/Recents sidebar sections

- **New `super_admin` role** above `admin`: everything an admin can do, plus
  org-wide insights and managing admins. `alex.gordo@cwglobalpeople.com` is
  promoted by a **data migration** (runs on prod deploy — no re-seed needed)
  and the seeder. `User::isAdmin()` stays true for both admin tiers;
  `isSuperAdmin()` gates the extras. Only the super admin can remove a super
  admin account (server-enforced; the Users page hides the dead-end button and
  shows a **Super admin** crown badge).
- **Feedback card is super-admin-only now.** The dashboard's Answer feedback
  card (below) moved from admins to the super admin; admins and members get no
  card (`feedback` prop is null).
- **Sidebar sections like claude.ai:** the chat sidebar now groups chats under
  **Starred** and **Recents** headers; the Starred section only appears when
  something is starred.

## Chat: starred chats + web-search toggle; Dashboard: answer-feedback card

- **Starred chats.** A star icon on every sidebar chat pins it to the top,
  like claude.ai (`conversations.starred`,
  `POST /chat/conversations/{id}/star`, throttled). Starring doesn't bump
  `updated_at`, so recency order under the pinned block stays stable. Optimistic
  UI with server reconciliation; works on the project chat sidebar too.
- **Web search toggle.** A **Web** button in the chat header (next to
  Thinking) turns Claude's web tools off per session — the turn then ships no
  `web_search`/`web_fetch` schemas and drops the web-answer style prompt
  (token savings), forcing knowledge-base/tools-only answers. ON by default,
  remembered in the browser, sent per message (`web=0`); shown only when
  `ANTHROPIC_WEB_TOOLS` is configured on.
- **Dashboard "Answer feedback" card.** The chat thumbs finally surface
  somewhere: up/down totals plus the most recent rated answers (excerpt, chat
  title, when). **Admins see the whole team's feedback** (with who left it);
  members see their own. List length via `DASHBOARD_FEEDBACK_LIMIT` (new
  `config/dashboard.php`, default 8). Also refreshed the stale "not built yet"
  docs list (dashboard placeholders, streaming).

## Security hardening: hard tool-approval gate, CSP, upload scanning + new docs

- **Hard approval gate (the big one).** Destructive Composio/NetSuite tool
  calls now **pause the turn before executing**: the tool loop's state is
  persisted (encrypted, `conversations.pending_tool_state`) and the chat shows
  an **Approve & run / Cancel** card listing each call and its exact input.
  Approve resumes the loop exactly where it paused; Cancel finalizes with a
  note and runs nothing. Consumed exactly once (double-click can't double-run);
  a new message supersedes a stale card; the card survives reloads (returned by
  `show()`). Destructive = verb token in the tool name
  (`ANTHROPIC_TOOL_GATE_VERBS`); reads never gate. With the gate on, the
  ask-in-text guardrail is dropped for client tools (no double prompt) but
  kept for MCP servers, which execute at Anthropic and can't be gated.
  (`ANTHROPIC_TOOL_HARD_GATE`, default on;
  `POST /chat/conversations/{id}/tools/decision`.)
- **Security headers + CSP.** New [`SecurityHeaders`](../app/Http/Middleware/SecurityHeaders.php)
  middleware: `Content-Security-Policy` (defense-in-depth over DOMPurify),
  `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`.
  The CSP is production-only: it ships with a per-request script **nonce**
  (`Vite::useCspNonce()`) so the layout's inline theme script runs under a
  strict `script-src`, and is skipped while `npm run dev` runs — browsers
  reject IPv6 CSP sources like the `[::1]` origin Vite binds (which broke the
  dev UI), and dev tooling (Vite HMR, Boost logger) injects nonce-less inline
  scripts. Config in new `config/security.php` (`SECURITY_HEADERS`,
  `SECURITY_CSP`, `SECURITY_CSP_POLICY`).
- **Optional upload virus scanning.** [`UploadScanner`](../app/Services/UploadScanner.php)
  runs ClamAV over chat uploads when `SECURITY_UPLOAD_SCAN=true` —
  **fail-closed**: a missing/broken scanner rejects uploads loudly instead of
  silently skipping. Off by default (Laravel's `mimes:` already content-sniffs).
- **Feedback route throttled** (was the one unthrottled chat write).
- **New docs:** [security.md](security.md) (every control, layer by layer),
  [performance.md](performance.md) (the cost/perf levers + tuning
  cheat-sheet), [netsuite.md](netsuite.md) (TBA + OAuth 2.0 setup, role
  permissions, the troubleshooting table from real debugging). Docs index +
  READMEs refreshed (PostgreSQL, sidebar, current feature set).

## Chat: cost routing — per-toolkit schema activation + tool-result caps

- **Per-toolkit routing.** Tool schemas are the silent cost: every connected
  toolkit's full schema list was serialized into the prompt on every turn, so a
  user with Slack + NetSuite connected paid for both even when asking about only
  one. Now, when **several** sources are connected, only the toolkit(s) the
  conversation mentions ship their schemas (keyword match over the replayed
  user turns; the toolkit key itself always matches; keywords are
  `.env`-overridable per toolkit). No match → everything ships, one source →
  routing bypassed, `COMPOSIO_TOOLKIT_ROUTING=false` → off. Matching scans the
  whole replayed window, so "now send that to the team" keeps Slack active
  from an earlier turn. Note routing changes the tools list, which invalidates
  the cache prefix on the *switch* turn — within a focused conversation the set
  stays stable and caching still wins.
- **Tool-result truncation.** A single tool result fed back to the model is now
  capped at `ANTHROPIC_TOOL_RESULT_MAX_CHARS` (default 20k chars, 0 disables) —
  within a turn the tool loop replays every result on each round, so one
  oversized payload multiplied fast. Truncated results end with a note telling
  the model it saw a partial result and should **narrow the query** instead of
  assuming completeness. (Tool results are never persisted — history is
  text-only — so cross-turn replay of bulky results was already impossible;
  SuiteQL rows were already capped at `NETSUITE_SUITEQL_MAX_ROWS`, and
  NetSuite's own `totalResults`/`hasMore` fields tell the model the full count.)

## Chat: claude.ai parity — thinking mode, auto-titles, auto-compact, continue, retry/edit/feedback, preferences, citations

- **Extended thinking (visible).** A **Thinking** toggle in the chat header
  requests adaptive thinking (summarized display) on supported models
  (`ANTHROPIC_THINKING_MODELS`); the thought process streams into a collapsible
  "Thought process" block above the answer and is persisted per message
  (`messages.thinking`). Skipped silently on non-supporting models and on the
  connected-tools loop (thinking + client tool replay is a follow-up).
- **Auto-generated titles.** After the first exchange, a cheap
  `ANTHROPIC_TITLE_MODEL` (Haiku) call replaces the truncated-first-message
  title; the sidebar updates live via a new `title` SSE event. Off with
  `ANTHROPIC_AUTO_TITLE=false`.
- **Auto-compact.** When a turn's replayed context crosses
  `ANTHROPIC_AUTO_COMPACT_TOKENS` (default 100k), the conversation is compacted
  on the queue ([`AutoCompactConversation`](../app/Jobs/AutoCompactConversation.php))
  using the same summarizer as the manual button (extracted to
  [`ConversationCompactor`](../app/Services/ConversationCompactor.php)).
- **Longer replies + Continue.** `ANTHROPIC_MAX_TOKENS` default raised
  4096 → 8192; the stream's `done` event now carries `stop_reason`, and when a
  reply hits the cap the UI shows a **Continue** button that resumes it
  (`ANTHROPIC_CONTINUE_PROMPT`). Assistant prefill isn't supported on 4.6+
  models, so Continue sends a normal follow-up turn.
- **Message affordances.** **Retry** (regenerate the last reply — the server
  drops it and replays history), **edit-and-resend** (pencil on the last user
  message; server drops the last exchange via `replace_last`), and **thumbs
  up/down** stored on `messages.feedback`
  (`POST /chat/messages/{id}/feedback`, toggleable).
- **User preferences.** New `users.chat_preferences` + a "Chat preferences"
  section under Settings → Profile; appended to the system prompt as
  `## User preferences` with a guard line (tone/format only, can't override
  safety rules).
- **Web citations surfaced.** Web-search citation metadata (previously dropped)
  is now collected on every path and appended as a **Sources:** footer of
  Markdown links.
- **History-window hardening.** The replayed window now always opens on a user
  turn (also after compaction), and messages already folded into a compaction
  summary can't be deleted by retry/edit. (Tool_use/tool_result blocks are never
  persisted, so trimming could never orphan a tool exchange — verified.)

## Chat: date grounding, prompt-injection defense, wider prompt caching & model check

- **Current date + user injected at runtime.** `buildSystemPrompt()` now appends
  `Current date: …` (day-level) and the user's name — the model stops reasoning
  from its training cutoff, and the web-tools note explicitly says to **search
  instead of mentioning a "knowledge cutoff"** for anything recent.
- **Untrusted-content guardrail.** New always-on note when any tools are active:
  tool/web/file content is **data, not instructions** (prompt-injection defense
  for a portal wired into NetSuite/Slack/web), plus brief natural narration
  before each tool call. Not skipped by auto-approve; override with
  `ANTHROPIC_TOOL_USE_PROMPT`.
- **Web-answer style.** Web-search answers stay conversational prose (no
  report-with-headers unless asked), paraphrase over quoting (quotes under ~15
  words, one per source), sources named naturally in the sentence.
- **Identity honesty + multilingual nuance.** AiMe BOT says it's built on Claude
  models by Anthropic if asked (never volunteers it), and keeps code/technical
  identifiers/table headers untranslated inside localized replies.
- **Prompt caching on the beta paths.** The MCP/web stream, `completeWithMcp`,
  and the connected-tools loop now send the system prompt as a **cached block**
  (`cache_control: ephemeral`); the breakpoint also covers the tool schemas and
  re-hits every loop round. (The plain path was already cached.)
- **Model picker check.** Validated all 8 picker ids against the live API (all
  valid); fixed the Fable 5 label ("Anthropic's most capable", not "creative
  writing") and added **`php artisan chat:check-models`** to catch stale ids
  before they 404 mid-chat. New optional `ANTHROPIC_BASE_URL`.

## Chat: Claude-style persona for AiMe BOT

- Rewrote the default system prompt (`config/services.php` →
  `anthropic.system_prompt`) in the style of Claude's own chat (claude.ai) while
  keeping the AiMe BOT identity: **response length calibrated** to the question
  (a sentence for simple asks, organized depth for complex ones), **prose by
  default** (bullets/headers only for genuinely multifaceted content — short
  lists read as "x, y, and z" in prose), **honest pushback** (disagree kindly
  when a premise is wrong instead of validating to be pleasant), **calibrated
  uncertainty** ("I believe" / "you should verify" — never invented account
  details or policy specifics), **grounding** (prefer connected tools over
  general knowledge for account/policy questions, and say when an answer is
  general rather than CW-specific), **question discipline** (at most one
  clarifying question, and try a useful answer under a stated assumption first),
  **graceful refusals** (a warm sentence + pivot, never a bulleted list of
  reasons), **no engagement-bait** (no "anything else?" endings), **owned
  mistakes** (one brief acknowledgment, no groveling), **no emojis** unless the
  user uses them first, and answers **in the user's language**. Still
  single-line overridable via `ANTHROPIC_SYSTEM_PROMPT`.

## Chat: UI polish — no empty "thinking" bubble, clearer auto-approve switch

- **No dead space while thinking.** The empty assistant placeholder bubble that
  showed alongside the "AiMe is thinking…" indicator is now hidden until the first
  token streams in, so there's no blank pill above the indicator.
- **Auto-approve is a labelled switch, not a relabelling button.** The header
  control now reads **Auto-approve** with an on/off toggle (clearer than a button
  whose text swapped between "Confirm actions" and "Auto-approve").
- **Confirmation before enabling auto-approve.** Flipping the switch on opens a
  dialog ("Auto-approve tool actions this session?") explaining that every
  connected-tool action — including create/update/delete/send — will run without
  asking; the state only flips once confirmed. Turning it off is immediate.

## Chat: Word (.docx) answer export + removed Composio NetSuite

- **Word export.** Added a **Word (.docx)** button to the answer action row, next
  to PDF. Like the XLSX writer, the `.docx` is built as minimal OOXML via
  `ZipArchive` (no PhpWord) — Markdown → headings, bullet/ordered lists,
  blockquotes, fenced code, GFM tables, and inline bold/italic/code.
  ([`ChatExportService::docx`](../app/Services/ChatExportService.php),
  `POST /chat/export/docx`.)
- **Images:** confirmed out of scope — Claude reads images but can't generate
  them (no image-generation model wired in); noted in the docs rather than faked.
- **Removed Composio NetSuite.** The native NetSuite integration (TBA + OAuth2)
  fully replaces it, so the stale Composio NetSuite artifacts (3 auth configs + 1
  connected account) were deleted from Composio. Config/toolkits already carried
  no NetSuite entry.

## Chat: fix web browsing with tools connected + tell the model it can browse/export

- **Web access now works when tools are connected.** Web search/fetch was only
  attached on the plain and MCP paths — as soon as a user connected Composio
  (Slack) or NetSuite, the connected-tools loop ran without web tools and the
  assistant said it couldn't browse. The connected-tools loop
  ([`ChatController::completeWithClientTools`](../app/Http/Controllers/ChatController.php))
  now runs on the **beta Messages endpoint** and **merges Claude's server-side web
  tools alongside the custom tools**, so Slack/NetSuite and web browsing work in
  the same turn.
- **Capability notes in the system prompt.** The model was over-refusing ("I can't
  browse the web", "I can't generate PDFs") even though both are available. Added
  two configurable, appended notes: a **web-access note** (while web tools are on)
  and a **downloadable-answers note** (replies can be downloaded as PDF/Markdown/
  CSV/XLSX from the buttons under each message, so it should write exportable
  content rather than refuse). Override with `ANTHROPIC_WEB_TOOLS_PROMPT` and
  `ANTHROPIC_FILES_PROMPT`. Reflection tests cover both notes.

## Chat: auto-approve tool actions (per-session toggle)

- Added an **Auto-approve / Confirm actions** toggle in the chat header (shown
  only when tools are connected). When set to auto-approve, the
  confirm-before-destructive-actions guardrail is **omitted** from the system
  prompt for that conversation, so the assistant acts without asking each time.
  Default stays **Confirm actions** (safe). The toggle is remembered in the
  browser (session-wide) and sent with each message; the backend records it on
  `conversations.auto_approve` per turn and
  [`ChatController::buildSystemPrompt`](../app/Http/Controllers/ChatController.php)
  skips the guardrail when it's on. New migration + boolean cast; reflection
  tests cover both states.

## Chat: web search/fetch + export answers (PDF / MD / CSV / XLSX)

- **Web access.** Enabled Claude's **native server-side web tools** — `web_search`
  + `web_fetch` — so AiMe can look things up online and read a URL. Wired into the
  plain and MCP chat paths (Composio/NetSuite tools still take precedence when
  connected); failures fall back to a plain answer. Config: `ANTHROPIC_WEB_TOOLS`,
  `ANTHROPIC_WEB_TOOL_MAX_USES`, `ANTHROPIC_WEB_FETCH_BETA`.
- **Export answers.** Each assistant answer gets a **Copy / .md / PDF** action row,
  plus **CSV / XLSX** when it contains a table.
  [`ChatExportService`](../app/Services/ChatExportService.php) renders Markdown →
  PDF (CommonMark + the already-installed dompdf), and parses GFM tables into CSV
  (native) or XLSX (a minimal OOXML writer via `ZipArchive` — **no PhpSpreadsheet**,
  so no `ext-gd` dependency). New endpoints `POST /chat/export/{pdf,sheet}` +
  [`ChatExportController`](../app/Http/Controllers/ChatExportController.php).
  `.md` downloads happen client-side. Tests cover PDF/CSV/XLSX + table parsing.

## NetSuite: add OAuth 2.0 as a second auth method (alongside TBA)

- The native NetSuite integration now supports **two auth methods**, chosen with
  a toggle in the connect dialog:
  - **Token-Based Auth (TBA)** — the original, recommended for a backend.
  - **OAuth 2.0 (Authorization Code Grant)** — paste the OAuth app's Client
    ID/Secret + Account ID, get redirected to NetSuite to approve, and we store
    + **auto-refresh** the access/refresh tokens. Redirect URI:
    `<APP_URL>/integrations/netsuite/callback`.
- `netsuite_connections` gains `auth_type` + OAuth2 columns (`client_id`,
  `client_secret`, `access_token`, `refresh_token`, `token_expires_at`, all
  encrypted); the TBA columns became nullable (new migration).
  [`NetsuiteService`](../app/Services/NetsuiteService.php) signs each request for
  TBA or sends a Bearer token (refreshing first) for OAuth2;
  [`NetsuiteController`](../app/Http/Controllers/NetsuiteController.php) gains an
  OAuth2 `callback`. New config: `NETSUITE_APP_DOMAIN`, `NETSUITE_OAUTH_SCOPES`,
  `NETSUITE_OAUTH_REDIRECT`, `NETSUITE_OAUTH_REFRESH_LEEWAY`. Note: either method
  still needs the NetSuite token/role to have REST Web Services + record
  permissions. Tests cover both flows.

## NetSuite: native Token-Based Auth integration (replaces Composio)

- **Why:** Composio's NetSuite toolkit is **OAuth 2.0 only**, and those tokens
  can't reliably read records — data calls 401 with `INVALID_LOGIN` even on an
  ACTIVE connection. NetSuite's own recommended server-to-server method is
  **Token-Based Authentication (TBA / OAuth 1.0a)**, so we built NetSuite as a
  **native integration** and removed it from Composio.
- **Connect flow:** the NetSuite card now opens a native modal collecting five
  values — **Account ID** + the Integration record's **Consumer Key/Secret** +
  an Access Token's **Token ID/Secret**. On submit the server signs a test
  SuiteQL query and, if the token is accepted, stores the four secrets
  **encrypted** (`netsuite_connections` table,
  [`NetsuiteConnection`](../app/Models/NetsuiteConnection.php)). No OAuth
  redirect, no Composio.
- **In chat:** AiMe gets two tools — **`netsuite_suiteql`** (read-only SuiteQL)
  and **`netsuite_get_record`** — run server-side with each request OAuth
  1.0a-signed by [`NetsuiteService`](../app/Services/NetsuiteService.php). They
  share the existing client-side tool loop with Composio, dispatched by the
  `netsuite_` name prefix.
- New: [`NetsuiteController`](../app/Http/Controllers/NetsuiteController.php),
  routes `integrations/netsuite/{connect,test}` + `DELETE integrations/netsuite`,
  `services.netsuite` config (`NETSUITE_*`), and `NetsuiteTest` coverage.
  NetSuite dropped from `services.composio.toolkits` and the Composio card.

## Chat: paste images + compact a conversation

- **Paste an image into the composer (Ctrl/Cmd+V).** A screenshot or copied
  image on the clipboard becomes an attachment (validated against the same
  size/count limits as the paperclip picker); plain-text pastes are untouched.
  ([`ChatPanel.vue`](../resources/js/components/ChatPanel.vue))
- **Compact a conversation, like Claude's `/compact`.** A **Compact** button in
  the chat header summarizes the transcript so far into a running summary stored
  on the conversation (`conversations.summary` + `summary_through_id`, new
  migration). Future turns replay only messages **newer than the summary**, which
  is injected into the system prompt — long chats keep answering without
  ballooning context or cost. The transcript stays visible with a small
  "compacted" pill; re-running folds in newer messages. New endpoint
  `POST /chat/conversations/{id}/compact`; prompt configurable via
  `ANTHROPIC_COMPACT_PROMPT` (default in `config/services.php`). Guard-path tests
  added.

## Integrations: "Currently connected" table + catalog search

- Added a **"Currently connected apps"** overview **table** at the top of the
  Integrations page listing every app linked through a card (Composio per-user
  links + event webhooks) with its category, connection type, endpoint, and
  quick Reconnect / Send test / Disconnect actions. Connected apps **move into
  this table and no longer render as a card** in the grid below (no duplicate).
  Shown only when something is connected; MCP servers keep their own section.
- Added a **search box** (top-right of the page header) that filters the catalog
  live by app name, description, or category (empty categories hide; a "no
  matches" note shows when nothing fits). ([`Integrations.vue`](../resources/js/pages/Integrations.vue))
- Dropped the "How" column from the connected table and show the app's own
  **details/description** there instead.
- Added **Airtable** as a Composio toolkit (Productivity & data) —
  `COMPOSIO_AIRTABLE_AUTH_CONFIG` in `config/services.php` + a card.

## Token limit is now optional (default unlimited)

- The per-user token cap is now **off by default**: `USAGE_TOKEN_LIMIT=0` (the new
  default) means **unlimited** — usage is still tracked and shown on the
  Dashboard, but chat is never blocked. Set a positive value to re-enable a cap.
- `TokenBudget::exceeded()` treats `limit <= 0` as unlimited; `snapshot()` reports
  `enabled=false` (cap inactive) so the Dashboard shows its "no limit configured"
  view with the live usage count. Tests cover the unlimited path.

## Fix: NetSuite tool execution (version + connected account)

- **Bug:** even with tools loaded, executing a NetSuite tool 404'd
  ("Tool … not found"). Composio's `execute` endpoint defaults `version` to an
  empty `00000000_00`, which doesn't exist for versioned toolkits.
- **Fix:** `execute()` now sends `version` (from `services.composio.tool_version`,
  default `latest`) — matching the version we list schemas with. New
  `COMPOSIO_TOOL_VERSION` env (used for both listing and execution).
- **Also:** `execute()` now pins to the **`connected_account_id`** we recorded
  (plus `user_id`) so a stale/duplicate account (e.g. left over after a NetSuite
  credential reset) can't be picked — a common `INVALID_LOGIN` cause. And
  `disconnect()` best-effort deletes the remote Composio account so reconnects
  start clean. Documented in [`composio-integrations.md`](composio-integrations.md) §3g–3i.
- Tests: execute sends `version`; execute pins to the recorded connected account.

## Fix: NetSuite (and other) tools not loading in chat

- **Bug:** connected NetSuite showed no tools in chat ("I only have Slack"). Two
  causes in `ComposioService::toolSchemas()`:
  1. Composio's tools list returns **zero** for some toolkits (NetSuite) unless
     `toolkit_versions=latest` is passed — the default version resolves empty.
  2. `important=true` alone is unreliable per toolkit — great for Slack (surfaces
     its late-alphabet send/search tools) but for NetSuite it returns only 5
     create/OAuth tools and **no read tools**.
- **Fix:** fetch tools in two deduped passes per toolkit — the curated
  `important` set **plus** the general list — always with
  `toolkit_versions=latest`, capped at `COMPOSIO_MAX_TOOLS` (default raised
  40 → 100). NetSuite now exposes all 86 tools (incl. `GET_CUSTOMER`,
  `LIST_RECORDS`, `RUN_SUITEQL_QUERY`); Slack keeps its send/search.
- Tests: `toolkit_versions=latest` is always sent; the two lists merge + de-dupe.
- Documented the whole Composio integration surface (API quirks, toolkit modes,
  endpoints, add/debug playbook) in
  [`docs/composio-integrations.md`](composio-integrations.md), linked from the
  docs index and features.

## Brand logo (favicon + in-app)

- Replaced the placeholder mark with the **CW Global People** logo everywhere:
  - **Browser/favicon** — regenerated `public/favicon.ico` (16/32/48),
    `favicon-16x16.png`, `favicon-32x32.png`, and `apple-touch-icon.png`
    (white-flattened for iOS) from the logo; removed the stale default
    `favicon.svg` and updated the `<link>`s in `app.blade.php`.
  - **In-app** — `AppLogoIcon.vue` now renders the real logo image (used by the
    sidebar, header, and auth screens); `AppLogo.vue` dropped the gold box that
    clashed with the full-colour mark.
- Moved the loose logo files out of `resources/` into
  `resources/js/assets/brand/` (`cwgp-logo.webp` + `cwgp-logo-alt.avif`); the
  webp is imported through Vite (fingerprinted at build).

## NetSuite connect (bring-your-own OAuth app)

- Made **NetSuite** a live Composio toolkit with a **credentials** flow (like
  ToppFive): the user pastes their NetSuite integration record's **Client ID /
  Secret** and **Account ID** in a connect dialog — **no Composio dashboard step**.
  Our server **creates the Composio auth config on the fly** (`use_custom_auth`,
  OAUTH2) from those credentials, then links with the account id as
  `connection_data.subdomain`, and returns the consent URL.
- Introduced two toolkit **modes** in `config/services.php`:
  - `managed` (Slack/GitHub/HubSpot/Airtable) — Composio owns the OAuth app,
    one-click connect (a pre-set `auth_config_id`).
  - `credentials` (NetSuite) — declares `credentials` (secret fields → create
    the auth config), `initiation` (non-secret fields → `connection_data`), and
    `optional_scopes` (opt-in scope toggles, e.g. **SuiteAnalytics Connect**).
- Service: `initiateWithCredentials()` + `createCustomAuthConfig()`; the link
  logic is shared. Controller: `POST /integrations/composio/{toolkit}/connect`
  validates the fields and returns `{redirect_url}` as JSON (secrets can't ride
  a GET redirect); the UI posts it and navigates. Managed toolkits keep the GET
  one-click connect.
- UI: a **credentials modal** (Client ID, Client Secret as password, Account ID,
  SuiteAnalytics checkbox) with inline errors; scrolls if long.
- Config: OAuth scopes via `COMPOSIO_NETSUITE_SCOPES`
  (default `restlets,rest_webservices`). Redirect URI for the NetSuite app is
  Composio's `https://backend.composio.dev/api/v1/auth-apps/add`.
- Tests: credentials toolkit offered without a pre-set auth config; connect
  validates creds+account id (no Composio call); connect creates the auth config
  (scopes merged) and links with the subdomain.

## NetSuite guide (OAuth 2.0) + roomier guide modal

- Rewrote the **NetSuite** setup guide to the correct **OAuth 2.0 (Authorization
  Code Grant)** flow brokered through Composio: enable SuiteCloud features →
  create an Integration record with the Composio redirect URI → copy Client
  ID/Secret (shown once) → find the Account ID → connect. Replaces the old
  Token-Based Auth steps.
- The **setup-guide modal** is now wider (`sm:max-w-2xl`) and its step list
  **scrolls** (`max-h-[60vh]`) so long guides aren't cramped or clipped; long
  values (like the redirect URI) wrap instead of overflowing.
  ([`Integrations.vue`](../resources/js/pages/Integrations.vue))

## Deploy script

- Added [`scripts/deploy.sh`](../scripts/deploy.sh) — a re-runnable server deploy:
  pull + `composer install --no-dev` + `npm ci && build` + `migrate --force` +
  cache rebuild + `queue:restart` (best-effort php-fpm reload + `restorecon`).
  Wired into `docs/DEPLOYMENT.md` §9, and the `.env` checklist now lists the
  `COMPOSIO_*` keys.

## Chat renders Markdown (tables, code, lists)

- Assistant replies now render as **formatted Markdown** instead of raw text —
  GFM **tables**, fenced code blocks, lists, headings, blockquotes, bold/italic,
  and links. A channel list or comparison now shows as a real table, not
  `| pipes |`. Rendered with `marked` and sanitized with `DOMPurify` before
  insertion (model output is never trusted raw); the styling uses the existing
  light/dark theme tokens and wide tables/code scroll horizontally inside the
  bubble. User messages stay plain text. ([`ChatPanel.vue`](../resources/js/components/ChatPanel.vue))
- Composio tool loading now requests each toolkit's **curated high-value tools**
  (`important=true`) rather than the first N alphabetically, so the model
  reliably gets the tools people actually ask for (list/search/send/fetch)
  instead of being cut off mid-alphabet. ([`ComposioService`](../app/Services/ComposioService.php))

## Composio connected apps + Integrations page redesign

- Added **Composio** as a hosted tool gateway so users can connect apps that
  don't support one-click MCP OAuth (they need a pre-registered app). Composio
  owns the OAuth apps, so there's **no per-app client id/secret** — one Composio
  API key + a per-toolkit auth-config id (in `config/services.php`, via `.env`).
  **Slack, GitHub, and HubSpot** are wired; more toolkits are just config
  entries + a card `composio:` key.
- **Per-user connections**: each user links their *own* account (Composio
  `user_id` = the AiMe user id), so AiMe acts as each person when using the tool.
- **Client-side tool loop (not MCP connector).** Composio's MCP endpoint needs
  an `x-api-key` header that Anthropic's server-side MCP connector can't send, so
  `ChatController` runs the tool loop itself: it fetches Composio tool schemas,
  offers them to Claude as normal tools, and executes each call **server-side**
  via Composio's REST API (`POST /api/v3/tools/execute/{slug}`) with the API key,
  looping until Claude stops. Tool count/rounds are capped
  (`COMPOSIO_MAX_TOOLS`, `COMPOSIO_MAX_TOOL_ROUNDS`).
- New: `composio_connections` table + `ComposioConnection` model,
  `ComposioService` (link, tool schemas, execute, live status), `ComposioController`
  (connect → consent → **status-verified** callback), routes under
  `integrations/composio/*`. `mcpEnabled` and the tool-safety guardrail also fire
  for Composio connections. The callback now **verifies the grant is ACTIVE with
  Composio** before marking a card connected (no more false "Connected").
- **Integrations page redesign**: everything is now organized **by category**
  (Communication, CRM, Developer tools, Files & documents, Automation, …). Each
  tool is one card that connects in one click via Composio where configured
  (Slack/GitHub/HubSpot), or shows "coming soon". Removed the broken
  **"Apps — one-click connect"** catalog (most vendors couldn't self-register an
  OAuth app). Automation (n8n/Zapier/Make/Webhooks) stays as outbound webhooks,
  and a de-emphasized **"Advanced — connect a server directly"** section at the
  bottom keeps the raw MCP-by-URL option for self-hosted/sensitive tools.

## Pin Composer platform to PHP 8.3 (reproducible deploys)

- Pinned `config.platform.php` to `8.3.31` in `composer.json` and regenerated
  `composer.lock` so dependencies resolve for the production PHP version (Symfony
  7.4 LTS + Laravel 13.x), regardless of the newer PHP on dev machines. This lets
  the server run a plain `composer install` on deploy instead of `composer update`.

## Pre-deploy hardening: indexes + soft deletes

- **Indexes** on the foreign-key columns we filter/join on — `messages.conversation_id`,
  `conversations.project_id`, `mcp_servers.user_id`, `skills.user_id`. PostgreSQL
  doesn't auto-index FKs (MySQL does), so these speed up the hot paths (loading a
  chat's messages, search, per-user MCP servers/skills). The `(user_id, updated_at)`
  listing indexes already existed.
- **Soft deletes** on user content — `conversations`, `projects`, `skills` — so an
  accidental delete is recoverable (`deleted_at`, excluded from queries by default,
  `restore()`-able). MCP servers/integrations stay hard delete (they hold secrets
  and are easy to reconnect); users stay hard delete (keeps the unique-email
  constraint simple). Migrations `2026_07_09_150000_add_query_indexes` and
  `2026_07_09_160000_add_soft_deletes`.

## Roles + admin user management

- **Admin/User roles** (`role` column on `users`, `User::isAdmin()`). Admins
  manage members on a new **Users** page (`/users`) — add (name/email/password/role,
  pre-verified) or remove users (not yourself). Gated by an `admin` middleware
  ([`EnsureUserIsAdmin`](../app/Http/Middleware/EnsureUserIsAdmin.php)); non-admins
  get 403. The "Users" nav item shows only for admins.
- **Seeder** now creates two admins — `alex.gordo@cwglobalpeople.com` and
  `dennies.salenga@cwglobalpeople.com` (`password`) — and demotes the dev
  `admin@example.com` to a plain user.
- Tests: role/isAdmin, admin-only access (403 for others), add/remove flow,
  validation, and the seeded admins. Migration
  `2026_07_09_140000_add_role_to_users_table`.

## PostgreSQL + deployment guide

- **Database is now PostgreSQL** (`config/database.php` defaults to `pgsql`;
  `.env.example` and `CLAUDE.md` updated). Chosen for native JSON — a fit for
  JSON-heavy sources like NetSuite saved searches. Chat search now uses `ILIKE`
  on Postgres for case-insensitive matching (MySQL/SQLite `LIKE` already ignores
  case), via a small `likeOperator()` driver check. Migrations are Postgres-safe
  (`after()` is a no-op, `json()`/`unsignedInteger` map cleanly).
- **[DEPLOYMENT.md](DEPLOYMENT.md)** added: server packages, Postgres setup,
  Nginx vhost tuned for SSE streaming, Supervisor queue worker, the full `.env`
  checklist (incl. the `APP_KEY`/OAuth/HTTPS gotchas), and a troubleshooting table.

## MCP URL validation + clearer "working" indicator

- **Endpoint validator.** Adding or one-click-connecting an MCP server now probes
  the URL first (`McpOAuthService::validateEndpoint` — a minimal MCP `initialize`
  handshake). A wrong URL that serves a **web page**, a **404**, or an
  **unreachable** host is rejected with a clear message *before* saving, instead
  of failing mid-chat. Conservative: a 401/403 auth challenge, JSON-RPC, SSE, or a
  redirect is accepted, so real servers are never blocked. SSRF-guarded.
- **Working indicator.** The chat's "Using &lt;tool&gt;…" status now stays visible
  while a server-side tool runs (create/update/etc.), even after text has started
  — a slow action no longer looks frozen.

## MCP failure no longer kills the chat

- If a connected MCP server is unreachable or misconfigured (e.g. a wrong server
  URL), the chat **falls back to a normal reply** instead of erroring out, with an
  inline note ("Couldn't reach your connected tools… check the server URL"). Both
  the streaming and non-streaming paths in `ChatController` now catch the MCP call
  and degrade gracefully rather than surfacing a 400/502.

## Expanded MCP catalog

- **Catalog expanded** after verifying live remote MCP endpoints against vendor
  docs: added **PayPal** (`https://mcp.paypal.com`), **Intercom**
  (`https://mcp.intercom.com/mcp`), and **Vercel** (`https://mcp.vercel.com`);
  corrected **HubSpot** (`https://mcp.hubspot.com`) and **Atlassian**
  (`https://mcp.atlassian.com/v1/mcp/authv2`). All confirmed OAuth-capable; URLs
  remain editable via `MCP_CATALOG_<APP>_URL`.

## Make.com automation + destructive-action guardrail

- **Make.com** added as a live automation provider (webhook-style, like n8n /
  Zapier). `INTEGRATION_WEBHOOK_PROVIDERS` and `INTEGRATIONS_LIVE` now default to
  `n8n,zapier,webhooks,make`.
- **Destructive-action guardrail.** When a user has tools connected, a policy is
  appended to the system prompt requiring the assistant to **confirm before any
  create/update/delete/send** and wait for approval (reads/searches are free).
  Config: `ANTHROPIC_TOOL_SAFETY` (default on), `ANTHROPIC_TOOL_SAFETY_PROMPT`.
  This is a policy guardrail, not a hard gate — the MCP connector runs tools
  server-side with no per-call interrupt; pair with least-privilege OAuth scopes
  for a hard limit (a client-side click-to-approve interrupt is future work).
- Tests: Make provider connect, and the guardrail is present only when an enabled
  MCP server exists (and honors the config toggle).

## App catalog (one-click connect) + live automation providers

- **One-click app catalog.** Popular MCP apps — GitHub, Notion, Linear, Sentry,
  Atlassian, Asana, **HubSpot**, **Airtable**, Stripe — now appear as cards with a
  single **Connect** that creates the MCP server from a config entry and runs the
  OAuth flow (no URL to paste). Cards are **state-aware** (Connected / Reconnect /
  Enable-Disable / remove). Catalog + URLs live in `config/integrations.php`
  (`mcp_catalog`, overridable via `MCP_CATALOG_<APP>_URL`). New `catalog_key`
  column on `mcp_servers`; `catalogConnect` route; the top MCP section now lists
  only **custom** (hand-added) servers, so catalog apps aren't duplicated.
- **Cross-app interop, clarified.** All enabled MCP servers' tools are handed to
  the model together, so the assistant can compare/copy/update across connected
  apps (e.g. HubSpot → Airtable) in one turn — documented on the page and in docs.
- **Automation section is now live for n8n, Zapier, and generic Webhooks.** The
  n8n-only webhook connect was generalized to any provider in
  `INTEGRATION_WEBHOOK_PROVIDERS`: generic `connectWebhook`/`testWebhook` routes,
  and `chat.completed` events dispatched to **every** connected provider (one
  queued job each, independent retries). These are event targets (connect by URL),
  not OAuth logins.
- Tests extended: catalog connect (create + redirect + unknown-key 404), generic
  webhook provider connect + unknown-provider 404, catalog/webhook props on the
  page. Migration `2026_07_09_130000_add_catalog_key_to_mcp_servers_table`.

## One-click OAuth for MCP servers

- **"Connect" now means OAuth**, not just pasting a token. Adding an MCP server,
  you pick **One-click OAuth** or **Paste a token**. For OAuth, clicking **Connect**
  sends you to the server's own approval page; approve and you're back, connected —
  no credentials to copy.
- Generic across tools via the **MCP authorization spec**:
  [`McpOAuthService`](../app/Services/Mcp/McpOAuthService.php) discovers the
  authorization server (RFC 9728 protected-resource metadata → RFC 8414 / OIDC
  server metadata), **self-registers a client** via Dynamic Client Registration
  (RFC 7591) where supported, runs **authorization-code + PKCE (S256)** with a CSRF
  `state`, and **auto-refreshes** the access token before it expires (config
  `MCP_OAUTH_REFRESH_LEEWAY`). Any server that ships a remote MCP server with OAuth
  "just works" — no per-tool code.
- **Security:** every discovered/redirected URL is **SSRF-guarded** via `PublicUrl`;
  client secret + access/refresh tokens are **encrypted at rest**; PKCE verifier and
  `state` live in the session only; unconnected OAuth servers are **skipped** at chat
  time rather than failing a turn with a 401. The token flows into the existing
  (unchanged) MCP connector as its `authorizationToken`.
- New: `auth_type` + OAuth columns on `mcp_servers`, connect/callback routes,
  `MCP_OAUTH_*` config, and tests (discovery/registration/PKCE, callback + CSRF,
  refresh, SSRF). Migration `2026_07_09_120000_add_oauth_to_mcp_servers_table`.

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
