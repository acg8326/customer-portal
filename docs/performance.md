# Performance & cost optimization

How the portal keeps chat fast and API spend bounded. The mental model:
**input tokens are cheap per-unit but multiply across every turn and tool
round, so (a) don't send what isn't needed, (b) cache what must be re-sent,
(c) bound what accumulates.** Output tokens cost ~5× input, so (d) don't
over-generate.

## (a) Don't send what isn't needed

- **Per-toolkit schema routing.** Tool schemas are the silent cost — a rich
  toolkit is hundreds of tokens per tool, re-sent every turn. With several
  sources connected, only the toolkit(s) the conversation mentions ship their
  schemas (keyword match over the replayed user turns; safe fallback to all).
  `COMPOSIO_TOOLKIT_ROUTING`, per-toolkit `*_KEYWORDS`, `NETSUITE_KEYWORDS`.
- **Schema cap:** `COMPOSIO_MAX_TOOLS` (default 100) bounds tools-per-toolkit.
- Web tools only attach when enabled; MCP definitions only when connected.

## (b) Cache what must be re-sent

- **Prompt caching on every path** (plain, MCP/web, connected-tools loop): one
  `cache_control` breakpoint on the system block. The API builds its prefix
  tools → system → messages, so that single breakpoint also caches all tool
  schemas — and re-hits on **every round** of a tool loop. Cache reads bill at
  ~10% of input price.
- The cache is **prefix-exact with a ~5-minute TTL** (refreshed per hit):
  - the injected date is **day-granularity** so the prefix stays byte-identical
    across turns (an H:i:s timestamp would guarantee 0% hits);
  - toolkit routing changes the tool list only when the conversation's topic
    changes — a focused session keeps a stable, cache-friendly set.

## (c) Bound what accumulates

- **History trimming:** only the most recent `ANTHROPIC_HISTORY_LIMIT`
  messages (default 40) are replayed, and the window always opens on a user
  turn.
- **Compaction:** manual (Compact button) and **automatic** — when a turn's
  replayed context crosses `ANTHROPIC_AUTO_COMPACT_TOKENS` (default 100k), a
  queued job summarizes the transcript; the summary replaces the older
  messages in the prompt. Cumulative on repeat.
- **Tool-result caps:** every result fed back to the model is truncated at
  `ANTHROPIC_TOOL_RESULT_MAX_CHARS` (default 20k chars) with a note telling
  the model to narrow the query; SuiteQL rows cap at
  `NETSUITE_SUITEQL_MAX_ROWS` (NetSuite's own `totalResults`/`hasMore` tell
  the model the full count). Tool results are **never persisted** — history is
  text-only, so bulky payloads are never replayed across turns.
- **Tool rounds:** `COMPOSIO_MAX_TOOL_ROUNDS` (default 8) bounds a single
  turn's loop.

## (d) Don't over-generate

- `ANTHROPIC_MAX_TOKENS` (default 8192) caps replies; the UI offers
  **Continue** when a reply legitimately needs more.
- The persona's anti-padding rules ("match depth to the question", prose over
  structure, never pad to look thorough) reduce average output length — a
  style choice that is quietly a cost control.
- Auto-titles use a small model (`ANTHROPIC_TITLE_MODEL`, Haiku) at 32 tokens.

## App-level performance

- **Streaming (SSE)** everywhere the UI talks to Claude — first tokens render
  immediately; tool calls surface as status ("Using …").
- **Queue for slow work:** webhooks and auto-compaction are jobs
  (`php artisan queue:work`, restarted on deploy) — nothing slow sits on the
  request path.
- **DB:** PostgreSQL with indexes on hot columns (`messages.conversation_id`,
  `conversations.user_id`/`project_id`), soft deletes, `ILIKE` for search.
- **Deploy** (`scripts/deploy.sh`): `composer --no-dev --optimize-autoloader`,
  `config:cache` / `route:cache` / `view:cache`, Vite production build,
  opcache reload, queue restart.
- **Budgets as guardrails:** per-user token budgets (`USAGE_TOKEN_LIMIT`) and
  per-route rate limits keep any one user's cost bounded.

## Tuning cheat-sheet

| Symptom | Lever |
| --- | --- |
| Replies cut off often | Raise `ANTHROPIC_MAX_TOKENS` |
| Long chats getting expensive | Lower `ANTHROPIC_AUTO_COMPACT_TOKENS` or `ANTHROPIC_HISTORY_LIMIT` |
| Tool turns expensive with many apps connected | Keep `COMPOSIO_TOOLKIT_ROUTING=true`, tighten `*_KEYWORDS` |
| Huge tool payloads | Lower `ANTHROPIC_TOOL_RESULT_MAX_CHARS` / `NETSUITE_SUITEQL_MAX_ROWS` |
| One user burning spend | Set `USAGE_TOKEN_LIMIT` > 0 |
