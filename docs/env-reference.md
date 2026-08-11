# Environment reference

Every `.env` key this app reads, with its real default and what it does.

**How to read this.** The codebase holds **287** `env()` keys. Almost all of them
have a sensible default in `config/*.php`, so a working `.env` is small — the
rule in [`CLAUDE.md`](../CLAUDE.md) is that a tunable must be *overridable* from
`.env`, **not** that it must be *present* in it. Copy in only what you actually
want to differ from the default. Tiers used below:

| Tier | Meaning |
| --- | --- |
| **Required** | No default in code. The app or that feature does not work without it. |
| **Recommended** | Has a default, but you want a deliberate value per environment. |
| **Optional** | Safe to leave out; the `config/` default is the intended value. |

> ⚠️ **An empty value is not "unset".** `KEY=` reads as the string `""`, which
> casts to `0` / `0.0` / `false` — not the config default. To fall back to a
> default, **comment the line out or delete it**. This bites hardest on numeric
> knobs: `USAGE_CACHE_READ_WEIGHT=` silently makes cache reads free.

> Production caches config (`php artisan config:cache`, run by
> `scripts/deploy.sh`). A new or changed key needs a re-cache to take effect.

---

## 1. Application

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `APP_NAME` | `Laravel` | Recommended | Also feeds `VITE_APP_NAME` in the UI. |
| `APP_ENV` | `production` | Recommended | `local` in development. |
| `APP_KEY` | — | **Required** | `php artisan key:generate`. Also the fallback passkey handle secret. |
| `APP_DEBUG` | `false` | Recommended | Must be `false` in production. |
| `APP_URL` | `http://localhost` | **Required** | Passkey relying-party ID + allowed origins derive from it; the gateway base URL developers use is `<APP_URL>/llm`. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `en` / `en` | Optional | |
| `APP_MAINTENANCE_DRIVER` | `file` | Optional | |
| `PASSKEYS_USER_HANDLE_SECRET` | `config('app.key')` | Optional | Falls back to `APP_KEY`. **Pin it explicitly before rotating `APP_KEY`** — otherwise every existing passkey stops working. |

## 2. Database, queue, cache, session, mail

PostgreSQL is required (`pdo_pgsql`). The queue must have a running worker
(`php artisan queue:work`) — auto-compaction, memory extraction, and webhook
dispatch are queued jobs.

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `DB_CONNECTION` | `pgsql` | **Required** | |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5432` | **Required** | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | **Required** | |
| `QUEUE_CONNECTION` | `database` | Recommended | `sync` would put compaction on the request path. |
| `CACHE_STORE` | `database` | Recommended | Holds the rate-limit gauge (short TTL). |
| `SESSION_DRIVER` | `database` | Recommended | |
| `SESSION_LIFETIME` | `120` | Optional | Minutes. |
| `SESSION_SECURE_COOKIE` | *(null)* | Recommended | Set `true` behind HTTPS. Not in `.env.example`. |
| `MAIL_MAILER` | `log` | Recommended | `log` swallows password resets — set a real mailer in production. |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` | — | Recommended | Per mailer. |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `hello@example.com` / `${APP_NAME}` | Recommended | |

The remaining ~200 framework keys (Redis, SQS, Beanstalkd, Memcached, log
handlers, session internals) are stock Laravel and intentionally absent from
`.env.example`. Leave them alone unless you are switching driver.

## 3. Claude — core chat

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `ANTHROPIC_API_KEY` | — | **Required** | Chat *and* gateway both dead without it. |
| `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` | Optional | Point the SDK at a different host. |
| `ANTHROPIC_MODEL` | `claude-opus-4-8` | Recommended | Workspace default for new chats; the super admin can override live on Analytics. |
| `ANTHROPIC_MODELS` | `''` | Optional | Override the picker catalog: `"id:Label,id:Label"`. |
| `ANTHROPIC_MAX_TOKENS` | `8192` | Recommended | Caps reply length — a cost control. **Continue** button covers legitimate overruns. |
| `ANTHROPIC_HISTORY_LIMIT` | `40` | Recommended | Past messages replayed per turn (`0` = no trim). Bounds context growth. |
| `ANTHROPIC_SYSTEM_PROMPT` | *(multi-line in config)* | Optional | Single-line override of the persona/guardrails. |
| `ANTHROPIC_COMPANY_CONTEXT` | *(multi-line in config)* | Optional | The "about this portal" block. |
| `ANTHROPIC_CHAT_LANGUAGES` | `English,Tagalog,Cebuano,Spanish,Chinese,Japanese,Korean` | Optional | |
| `ANTHROPIC_CONTINUE_PROMPT` | `Continue exactly where you left off…` | Optional | |
| `CHAT_SSE_PADDING` | `4096` | Optional | Pads tiny SSE status frames so buffering proxies flush them. |

### Thinking

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `ANTHROPIC_THINKING` | `true` | Optional | Enables the per-session thinking toggle in the chat header. |
| `ANTHROPIC_THINKING_MODELS` | `claude-opus-4-8,claude-opus-4-7,claude-sonnet-5,claude-sonnet-4-6,claude-fable-5` | Optional | Toggle only applies on adaptive-thinking-capable models. |

### Web tools (server-side search + fetch)

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `ANTHROPIC_WEB_TOOLS` | `true` | Optional | Master switch for search + fetch. |
| `ANTHROPIC_WEB_TOOL_MAX_USES` | `5` | Optional | Per-request cap — bounds cost of a research turn. |
| `ANTHROPIC_WEB_FETCH` | `true` | Optional | Separate toggle so fetch can be disabled if its beta flag drifts. |
| `ANTHROPIC_WEB_FETCH_BETA` | `web-fetch-2025-09-10` | Optional | Bump when the API version changes. |
| `ANTHROPIC_WEB_TOOLS_PROMPT` | *(multi-line)* | Optional | |
| `ANTHROPIC_CONNECTED_TOOLS_PROMPT` | `true` | Recommended | Names the user's connected sources in the prompt so the assistant checks them instead of guessing. |

### Titles, memory, compaction

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `ANTHROPIC_AUTO_TITLE` | `true` | Optional | One cheap small-model call after the first exchange. |
| `ANTHROPIC_TITLE_MODEL` | `claude-haiku-4-5` | Optional | Deliberately Haiku, not the chat model. |
| `ANTHROPIC_TITLE_PROMPT` | `Generate a concise 2-5 word title…` | Optional | |
| `ANTHROPIC_MEMORY` | `true` | Optional | claude.ai-style durable facts injected into the system prompt. |
| `ANTHROPIC_MEMORY_MODEL` | `claude-haiku-4-5` | Optional | |
| `ANTHROPIC_MEMORY_EVERY` | `10` | Optional | Extract every N messages. |
| `ANTHROPIC_MEMORY_MAX_ITEMS` | `15` | Optional | |
| `ANTHROPIC_MEMORY_MAX_ITEM_CHARS` | `200` | Optional | Not in `.env.example`. |
| `ANTHROPIC_MEMORY_MAX_TRANSCRIPT_CHARS` | `12000` | Optional | Not in `.env.example`. Caps what the extraction call reads. |
| `ANTHROPIC_AUTO_COMPACT_TOKENS` | `100000` | Recommended | Background compaction once a turn's replayed context crosses this (`0` disables). The main lever on long-chat cost. |
| `ANTHROPIC_COMPACT_PROMPT` | *(multi-line)* | Optional | |

### Uploads and project files

| Key | Default | Tier |
| --- | --- | --- |
| `ANTHROPIC_UPLOADS_ENABLED` | `true` | Optional |
| `ANTHROPIC_UPLOADS_MAX_FILES` | `5` | Optional |
| `ANTHROPIC_UPLOADS_MAX_SIZE_KB` | `10240` | Optional |
| `ANTHROPIC_UPLOADS_MIMES` | `jpg,jpeg,png,gif,webp,pdf,docx,xlsx,csv,txt,md` | Optional |
| `ANTHROPIC_UPLOADS_EXTRACT_MAX_CHARS` | `50000` | Optional |
| `ANTHROPIC_PROJECT_MIMES` | `docx,xlsx,csv,txt,md` | Optional |
| `ANTHROPIC_PROJECT_MAX_FILES` | `10` | Optional |
| `ANTHROPIC_PROJECT_MAX_CHARS` | `100000` | Optional |

### Tool safety

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `ANTHROPIC_TOOL_SAFETY` | `true` | Recommended | Model must confirm before destructive tool actions. |
| `ANTHROPIC_TOOL_HARD_GATE` | `true` | Recommended | Hard Approve/Cancel card — nothing runs until the user approves. |
| `ANTHROPIC_TOOL_GATE_VERBS` | `create,update,delete,remove,send,post,write,add,…,append,clear,insert,copy,format,…` | Recommended | Names a tool destructive by verb token. Extend it when wiring a toolkit that names writes differently — Sheets' `CLEAR_VALUES` matched nothing until `clear` was added. |
| `ANTHROPIC_TOOL_RESULT_MAX_CHARS` | `20000` | Recommended | Truncates every tool result fed back to the model. Cost control. |
| `ANTHROPIC_TOOL_SAFETY_PROMPT` / `ANTHROPIC_TOOL_USE_PROMPT` | *(multi-line)* | Optional | |

## 4. LLM gateway (Claude Code through AiMe)

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `CHAT_GATEWAY_ENABLED` | `false` | Recommended | Off by default. When on, developers set `ANTHROPIC_BASE_URL=<APP_URL>/llm` and `ANTHROPIC_AUTH_TOKEN=<their AiMe token>`. |
| `CHAT_GATEWAY_TOKEN_PREFIX` | `aime` | Optional | Visible prefix on issued tokens; the secret itself is stored hashed. |
| `ANTHROPIC_RATE_LIMIT_CAPTURE` | `true` | Optional | Captures Anthropic's own `anthropic-ratelimit-*` headers — gateway traffic only. |
| `ANTHROPIC_RATE_LIMIT_CACHE_TTL` | `300` | Optional | Seconds. Short so a quiet gateway shows "no data" rather than stale numbers. |
| `CHAT_REQUEST_LOG_ENABLED` | `true` | Recommended | Per-request log (Analytics → Logs). **Gateway cost reporting depends on this** — the gateway keeps no transcript, so `request_logs` is its only record. |

## 5. Token budgets and cost

Three independent rolling windows. A cap of `0` means **unlimited for that
window**: usage is still tracked and displayed, but never blocks. All the
limits and durations below are *defaults* — the super admin overrides them live
on **Analytics → Usage** (stored in `app_settings`), and per-user overrides on
the same page win over the workspace value.

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `USAGE_LIMIT_ENABLED` | `true` | Recommended | Master switch for tracking *and* display. |
| `USAGE_TOKEN_LIMIT` | `0` | Recommended | Period window cap. `0` = unlimited. |
| `USAGE_PERIOD_DAYS` | `30` | Optional | |
| `USAGE_SESSION_TOKEN_LIMIT` | `0` | Recommended | Session cap. The fastest-feedback window for a runaway coding agent. |
| `USAGE_SESSION_HOURS` | `5` | Optional | Mirrors Claude's own session window. |
| `USAGE_WEEKLY_TOKEN_LIMIT` | `0` | Recommended | Weekly cap. |
| `USAGE_WEEKLY_DAYS` | `7` | Optional | |
| `USAGE_CACHE_READ_WEIGHT` | `0.1` | Optional | What a cache-read token costs against a budget, relative to fresh input. `0` = free, `1` = same as fresh. |
| `USAGE_CACHE_WRITE_WEIGHT` | `1.25` | Optional | Same for a cache write. |

### Cost reporting (Analytics → Cost & caching)

Estimates for reporting only — never what Anthropic actually bills.

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `LLM_PRICES` | `''` | Optional | Single-line override of the per-model table: `"model:input:output,…"` in USD per MTok. |
| `LLM_PRICE_DEFAULT_INPUT` | `3.0` | Optional | Fallback for unlisted models. |
| `LLM_PRICE_DEFAULT_OUTPUT` | `15.0` | Optional | |
| `LLM_CACHE_READ_MULTIPLIER` | `0.1` | Optional | Prices cached tokens in **dollars**. Keep in step with `USAGE_CACHE_READ_WEIGHT`, which charges the same tokens against **budgets**. |
| `LLM_CACHE_WRITE_MULTIPLIER` | `1.25` | Optional | Likewise with `USAGE_CACHE_WRITE_WEIGHT`. |

## 6. Other LLM providers (plain chat only)

One **global** key per provider — no per-user keys, so usage stays inside the
org budget. Providers without a key show as locked in the picker, and users can
request access from there. These get plain chat only; tools, web, and files stay
with Claude. Each accepts an optional `{PREFIX}_MODELS="id:Label|hint,…"` and
`{PREFIX}_BASE_URL`.

| Key | Base URL default | Tier |
| --- | --- | --- |
| `OPENAI_API_KEY` | `https://api.openai.com/v1` | Optional |
| `GEMINI_API_KEY` | `https://generativelanguage.googleapis.com/v1beta/openai` | Optional |
| `DEEPSEEK_API_KEY` | `https://api.deepseek.com/v1` | Optional |
| `GROQ_API_KEY` | `https://api.groq.com/openai/v1` | Optional |
| `MISTRAL_API_KEY` | `https://api.mistral.ai/v1` | Optional |
| `XAI_API_KEY` | `https://api.x.ai/v1` | Optional |

## 7. Image generation and speech

Claude does neither, so these route to OpenAI-compatible endpoints and fall back
to `OPENAI_API_KEY` / `OPENAI_BASE_URL` when their own keys are unset. Each
request charges the user's budget a **flat token-equivalent**, not measured usage.

| Key | Default | Tier |
| --- | --- | --- |
| `IMAGE_API_KEY` | falls back to `OPENAI_API_KEY` | Optional |
| `IMAGE_MODEL` | `gpt-image-1` | Optional |
| `IMAGE_SIZE` | `1024x1024` | Optional |
| `IMAGE_QUALITY` | `medium` | Optional |
| `IMAGE_PROVIDER_NAME` | `OpenAI` | Optional |
| `IMAGE_TOKEN_COST` | `5000` | Optional |
| `SPEECH_API_KEY` | falls back to `OPENAI_API_KEY` | Optional |
| `SPEECH_STT_MODEL` | `gpt-4o-transcribe` | Optional |
| `SPEECH_TTS_MODEL` | `gpt-4o-mini-tts` | Optional |
| `SPEECH_TTS_VOICE` | `alloy` | Optional |
| `SPEECH_TTS_MAX_CHARS` | `4000` | Optional |
| `SPEECH_MAX_AUDIO_KB` | `15360` | Optional |
| `SPEECH_TOKEN_COST` | `500` | Optional |

## 8. Connected tools — Composio

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `COMPOSIO_API_KEY` | — | Required *(for the feature)* | |
| `COMPOSIO_BASE_URL` | `https://backend.composio.dev` | Optional | |
| `COMPOSIO_MAX_TOOLS` | `100` | Recommended | Bounds tools-per-toolkit. Schemas are re-sent every turn. |
| `COMPOSIO_MAX_TOOL_ROUNDS` | `8` | Recommended | Bounds one turn's tool loop. |
| `COMPOSIO_TOOL_VERSION` | `latest` | Optional | Not in `.env.example`. |
| `COMPOSIO_TOOLKIT_ROUTING` | `true` | Recommended | Only ships schemas for toolkits the conversation mentions. Biggest tool-cost lever. |
| `COMPOSIO_SLACK_AUTH_CONFIG` | — | Optional | Plus `COMPOSIO_SLACK_MCP_SERVER_ID`. |
| `COMPOSIO_SLACK_KEYWORDS` | `slack,channel,message,dm,thread,workspace,reminder,canvas` | Optional | |
| `COMPOSIO_GITHUB_AUTH_CONFIG` | — | Optional | |
| `COMPOSIO_GITHUB_KEYWORDS` | `github,repo,repository,pull request,pr,issue,commit,branch,release` | Optional | Not in `.env.example`. |
| `COMPOSIO_HUBSPOT_AUTH_CONFIG` | — | Optional | |
| `COMPOSIO_HUBSPOT_KEYWORDS` | `hubspot,crm,deal,pipeline,contact,ticket,lead` | Optional | Not in `.env.example`. |
| `COMPOSIO_AIRTABLE_AUTH_CONFIG` | — | Optional | |
| `COMPOSIO_AIRTABLE_KEYWORDS` | `airtable,base,table,grid,view` | Optional | Not in `.env.example`. |
| `COMPOSIO_GOOGLESHEETS_AUTH_CONFIG` | — | Optional | Composio-managed OAuth2 — no client id/secret needed. |
| `COMPOSIO_GOOGLESHEETS_KEYWORDS` | `sheet,sheets,spreadsheet,google sheet,worksheet,tab,cell,column,row,range,formula,csv,workbook` | Recommended | 36 tools — the largest toolkit, so routing matters most here. |

## 9. NetSuite

NetSuite is **native Token-Based Auth**, not Composio.

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `NETSUITE_ENABLED` | `true` | Optional | |
| `NETSUITE_MULTI_ACCOUNT` | `false` | Optional | |
| `NETSUITE_TIMEOUT` | `30` | Optional | Seconds. |
| `NETSUITE_SUITEQL_MAX_ROWS` | `100` | Recommended | Caps rows fed back to the model. Cost control. |
| `NETSUITE_KEYWORDS` | `netsuite,suiteql,invoice,customer,sales order,…` | Optional | Feeds toolkit routing. |
| `NETSUITE_REST_DOMAIN` | `suitetalk.api.netsuite.com` | Optional | |
| `NETSUITE_APP_DOMAIN` | `app.netsuite.com` | Optional | |
| `NETSUITE_OAUTH_SCOPES` | `rest_webservices` | Optional | |
| `NETSUITE_OAUTH_REDIRECT` | — | Optional | |
| `NETSUITE_OAUTH_REFRESH_LEEWAY` | `120` | Optional | Seconds before expiry to refresh. |

## 10. MCP servers

| Key | Default | Tier |
| --- | --- | --- |
| `ANTHROPIC_MCP_BETA` | `mcp-client-2025-04-04` | Optional |
| `MCP_OAUTH_CLIENT_NAME` | `CWGP-AIMe` | Optional |
| `MCP_OAUTH_SCOPES` | `''` | Optional |
| `MCP_OAUTH_TIMEOUT` | `10` | Optional |
| `MCP_OAUTH_REFRESH_LEEWAY` | `120` | Optional |

Catalog URLs (all optional, none in `.env.example`) — override only if a vendor
moves its endpoint: `MCP_CATALOG_GITHUB_URL` `https://api.githubcopilot.com/mcp/`,
`MCP_CATALOG_NOTION_URL` `https://mcp.notion.com/mcp`,
`MCP_CATALOG_LINEAR_URL` `https://mcp.linear.app/mcp`,
`MCP_CATALOG_SENTRY_URL` `https://mcp.sentry.dev/mcp`,
`MCP_CATALOG_ATLASSIAN_URL` `https://mcp.atlassian.com/v1/mcp/authv2`,
`MCP_CATALOG_ASANA_URL` `https://mcp.asana.com/sse`,
`MCP_CATALOG_HUBSPOT_URL` `https://mcp.hubspot.com`,
`MCP_CATALOG_AIRTABLE_URL` `https://mcp.airtable.com/mcp`,
`MCP_CATALOG_STRIPE_URL` `https://mcp.stripe.com`,
`MCP_CATALOG_PAYPAL_URL` `https://mcp.paypal.com`,
`MCP_CATALOG_INTERCOM_URL` `https://mcp.intercom.com/mcp`,
`MCP_CATALOG_VERCEL_URL` `https://mcp.vercel.com`.

## 11. Outbound integrations (n8n / webhooks)

| Key | Default | Tier |
| --- | --- | --- |
| `INTEGRATIONS_LIVE` | `n8n,zapier,webhooks,make` | Optional |
| `INTEGRATION_WEBHOOK_PROVIDERS` | `n8n,zapier,webhooks,make` | Optional |
| `INTEGRATION_N8N_TIMEOUT` | `8` | Optional |
| `INTEGRATION_N8N_SECRET_HEADER` | `X-AiMe-Secret` | Optional |

## 12. Security

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `SECURITY_HEADERS` | `true` | Recommended | |
| `SECURITY_CSP` | `true` | Recommended | Allows the Vite dev server automatically while `npm run dev` runs. |
| `SECURITY_CSP_POLICY` | *(policy string in config)* | Optional | |
| `SECURITY_UPLOAD_SCAN` | `false` | Optional | **Fail-closed** when enabled — a broken scanner rejects uploads rather than silently passing them. |
| `SECURITY_UPLOAD_SCANNER` | `clamscan` | Optional | |
| `SECURITY_UPLOAD_SCAN_TIMEOUT` | `30` | Optional | |

## 13. Rate limits and retention

| Key | Default | Tier | Notes |
| --- | --- | --- | --- |
| `RATE_LIMIT_CHAT` | `30` | Optional | Requests/min. |
| `RATE_LIMIT_SEARCH` | `60` | Optional | |
| `RATE_LIMIT_INTEGRATIONS` | `20` | Optional | |
| `RATE_LIMIT_INTEGRATION_TEST` | `10` | Optional | |
| `RETENTION_CHAT_DAYS` | `0` | Recommended | `0` keeps chats forever; a positive value hard-deletes idle conversations (`chat:prune`, scheduled daily). |
| `RETENTION_TRASH_DAYS` | `30` | Optional | |
| `DASHBOARD_FEEDBACK_LIMIT` | `8` | Optional | Feedback entries on the super-admin card. |

> Note: the **LLM gateway routes carry no rate-limit throttle** — the token
> budget is the only guard on that surface.

---

## Minimum working `.env`

```dotenv
APP_NAME="CWGP AiMe"
APP_ENV=production
APP_KEY=                      # php artisan key:generate
APP_DEBUG=false
APP_URL=https://aime.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cwgp_aime
DB_USERNAME=cwgp
DB_PASSWORD=

QUEUE_CONNECTION=database     # a worker must be running
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database

MAIL_MAILER=smtp              # `log` swallows password resets
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="aime@example.com"

ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-opus-4-8
```

Everything else runs on its `config/` default. Add keys as you turn features on.

## Reviewing what a deployment actually overrides

```sh
# Keys set in .env (names only — no values printed)
grep -oE '^[A-Z_0-9]+' .env

# Every key the code can read, with its default
grep -rn "env('" config/ | sed -E "s/.*env\('([A-Z_0-9]+)'/\1/"

# Effective value of any config path
php artisan tinker --execute='dump(config("usage"));'
```

Related: [`performance.md`](performance.md) for which of these are cost levers,
[`llm-gateway.md`](llm-gateway.md) for the gateway keys in context, and
[`features.md`](features.md) for what each feature does.
