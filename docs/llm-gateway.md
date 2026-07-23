# LLM gateway — AiMe as the backend for Claude Code

How AiMe lets developers use **Claude Code** (or any Anthropic-compatible
client) while their traffic runs through AiMe — so the organisation gets one
shared API key, a **per-user model pin**, and a **per-user token budget** that
the raw Anthropic API and Claude Code don't provide on their own.

Feature-flagged: **off by default**, turned on with `CHAT_GATEWAY_ENABLED=true`.

---

## 1. The big idea (and what it is *not*)

**We did NOT build a Claude Code agent, and we do not use the Claude Agent
SDK.** That distinction matters, so it's spelled out here:

- **Claude Code** — the VS Code extension / CLI — is an unmodified, off-the-shelf
  client. Its **agent loop runs entirely on the developer's machine**: it reads
  and edits files, runs terminal commands, plans, and calls tools locally. AiMe
  has no part in that loop and never sees the developer's filesystem.
- **AiMe** is an **LLM gateway** — a thin, transparent HTTP proxy that speaks the
  **Anthropic Messages API**. Claude Code already talks that protocol to
  `api.anthropic.com`. We simply point it at AiMe instead.

So the division of labour is:

```
┌────────────────────────────┐        ┌──────────────────────────┐        ┌─────────────────────┐
│  Claude Code (developer PC) │        │   AiMe  (this app)       │        │  Anthropic API      │
│  • the agent loop           │  HTTPS │   the LLM gateway        │  HTTPS │  api.anthropic.com  │
│  • reads/edits files        │ ─────▶ │  • auth per-user token   │ ─────▶ │  • the real model   │
│  • runs tools locally       │        │  • force assigned model  │        │  • central API key  │
│  • builds the API request   │ ◀───── │  • enforce token budget  │ ◀───── │  • streams response │
│                             │  SSE   │  • record usage; relay   │  SSE   │                     │
└────────────────────────────┘        └──────────────────────────┘        └─────────────────────┘
```

Claude Code is the brain; AiMe is the **governed doorway** to the model. AiMe
only touches three things on the way through: **authentication**, the **`model`
field**, and **usage accounting**. Everything else is relayed byte-for-byte.

### Why a gateway and not the Agent SDK / OAuth?

- We wanted developers to keep using **their own** editor tool (Claude Code),
  not a bespoke agent we host. A gateway leaves their workflow untouched.
- Claude Code's login screen is hardwired to Anthropic — **custom OAuth/OIDC is
  not supported**. But Claude Code honours `ANTHROPIC_BASE_URL` +
  `ANTHROPIC_AUTH_TOKEN`, which is exactly the seam a gateway needs. So the
  integration is "set two environment variables," nothing more.
- One central Anthropic key stays on the server; developers never hold it.

---

## 2. Request lifecycle

1. Claude Code sends `POST {ANTHROPIC_BASE_URL}/v1/messages` with
   `Authorization: Bearer <the developer's AiMe token>`.
   With `ANTHROPIC_BASE_URL=https://aime.cwglobal.ai/llm`, that resolves to
   `https://aime.cwglobal.ai/llm/v1/messages`.
2. **Route group** (`routes/gateway.php`, prefix `llm/v1`, registered in
   `bootstrap/app.php`) runs **without** the web session / CSRF stack — it's a
   pure API surface.
3. **`GatewayAuth` middleware**: 404s if the gateway is disabled; otherwise
   reads the bearer token, looks up an **active** `GatewayToken` by SHA-256
   hash, and resolves the request user. Missing/invalid → `401` in
   Anthropic's own error shape. Touches `last_used_at` (at most once/minute).
4. **`GatewayController@messages`**: if the user is over budget →
   `429 rate_limit_error` **before** any upstream call. Otherwise it forwards
   the **raw request body** to `AnthropicGateway`.
5. **`AnthropicGateway`**:
   - **Pins the model** — rewrites the top-level `model` to the user's
     `assigned_model` when one is set (same `ChatController::effectiveModel()`
     the portal uses). If no model is pinned, the client's choice stands.
   - **Forwards** to `{base_url}/v1/messages` with the **central `x-api-key`**
     plus the client's `anthropic-version` / `anthropic-beta` headers.
   - **Streaming** (`stream: true`): relays the SSE bytes verbatim while a small
     parser tallies token usage, recorded once the stream ends.
   - **Non-streaming**: relays the response bytes verbatim; reads `usage` to
     record the spend.
6. **Usage** is charged to the user's rolling window via `TokenBudget`, the same
   ledger the portal chat uses — so a developer's Claude Code usage and their
   in-app chat usage share one budget.

`POST /llm/v1/messages/count_tokens` is proxied the same way but **not** billed
(it's a metadata call Claude Code makes before sending).

---

## 3. Why we forward the *raw* body (important)

The gateway must **not** JSON-decode-then-re-encode the request. In PHP an empty
JSON object `{}` decodes to an empty array `[]`, and re-encoding emits `[]` — a
JSON *array*. Claude Code sends no-parameter tools whose schema is
`"input_schema": { "type": "object", "properties": {} }`, so a round trip would
turn `properties: {}` into `properties: []` and Anthropic rejects it:

```
API Error: 400 tools.N.custom.input_schema.properties: Input should be an object
```

So `AnthropicGateway` forwards the raw bytes and rewrites **only** the `model`
field via an object-decode (`json_decode($raw, false)`, which preserves `{}`).
The upstream response is likewise relayed verbatim.

---

## 4. Governance: model pin + token budget

Both are **per-user overrides** on the `users` table, set by the super admin on
**Analytics → Usage** (see [features.md](features.md)). Session and weekly
token budgets exist too, same override semantics — see the tiered-budget
entry in [changelog.md](changelog.md).

- **`assigned_model`** (nullable) — pins the model. Enforced **server-side** in
  `ChatController::effectiveModel()`, so it applies identically to the portal
  chat *and* the gateway. Claude Code's own model picker is **overridden**: the
  developer can select anything, but the request runs on the pinned model.
  `null` = the developer's choice stands.
- **`token_limit`** (nullable) — the personal cap checked first by
  `TokenBudget`. `null` = inherit the workspace limit; `0` = unlimited for that
  user; a positive value caps them. Over budget → the gateway returns `429`.

Because governance lives at the gateway, a developer cannot escape it by
changing anything in Claude Code.

---

## 5. Developer tokens

Developers self-serve at **Settings → Developer access**
(`GatewayTokenController`, only visible when the gateway is enabled):

- **Generate** a named token — the plaintext is `{prefix}_{40 random chars}`
  (`CHAT_GATEWAY_TOKEN_PREFIX`, default `aime`), shown **once**. Only a SHA-256
  **hash** + the last four chars are stored (`gateway_tokens` table).
- **Revoke** — soft-revokes (`revoked_at` kept for audit) and the token
  disappears from the list; it stops authenticating immediately.
- The page includes a step-by-step VS Code setup guide and a copy-ready
  `settings.json` block (`claudeCode.environmentVariables` +
  `claudeCode.preferredLocation: "panel"`), auto-filled with the fresh token.

---

## 6. Developer setup (VS Code Claude Code)

1. Install the **Claude Code** extension.
2. Generate a token at **Settings → Developer access** and copy it.
3. `Ctrl`+`Shift`+`P` → **Preferences: Open User Settings (JSON)** and paste:
   ```jsonc
   {
     "claudeCode.environmentVariables": [
       { "name": "ANTHROPIC_BASE_URL", "value": "https://aime.cwglobal.ai/llm" },
       { "name": "ANTHROPIC_AUTH_TOKEN", "value": "<your-token>" },
       { "name": "ANTHROPIC_LOG", "value": "debug" }
     ],
     "claudeCode.preferredLocation": "panel"
   }
   ```
4. `Ctrl`+`Shift`+`P` → **Developer: Reload Window**.
5. Open Claude Code and chat. The token's "last used" on the Developer access
   page confirms the connection.

> **`ANTHROPIC_BASE_URL` belongs only in the client.** Never set it in the
> **server's** `.env` — the server must reach the *real* `api.anthropic.com`.
> Setting it on the server makes the gateway forward to itself (a loop that
> times out). Server `.env` keeps `ANTHROPIC_API_KEY` only.

---

## 7. Configuration

`.env` → `config/services.php` under `anthropic.gateway`:

| Key                          | Default | Meaning                                         |
| ---------------------------- | ------- | ----------------------------------------------- |
| `CHAT_GATEWAY_ENABLED`       | `false` | Master switch. Off → `/llm/*` 404s, page hidden |
| `CHAT_GATEWAY_TOKEN_PREFIX`  | `aime`  | Prefix on generated tokens (cosmetic)           |

The upstream is `config('services.anthropic.base_url')` (default
`https://api.anthropic.com`) and the central key is
`config('services.anthropic.key')` (`ANTHROPIC_API_KEY`).

---

## 7a. Visibility into gateway traffic (Analytics)

The super admin can see gateway activity at **Analytics** (`/analytics`, see
[features.md](features.md)):

- **Rate limits tab** — Anthropic's own org-wide rate-limit response headers,
  captured from every gateway request. **This is gateway-only.** The in-app
  chat calls Anthropic through the official PHP SDK, which returns typed
  response objects and never exposes the raw HTTP response — there is no
  capture point on that path. Toggle: `ANTHROPIC_RATE_LIMIT_CAPTURE` (default
  on); `ANTHROPIC_RATE_LIMIT_CACHE_TTL` controls how quickly a quiet gateway
  shows as "no data" (default 300s).
- **Logs tab** — every gateway request (and every chat request) as a row:
  user, model, tokens, latency, status. Filterable by surface, so you can
  isolate `gateway`-only traffic. Toggle: `CHAT_REQUEST_LOG_ENABLED`.

---

## 8. Deploy notes

- The `assigned_model` / `token_limit` columns and the `gateway_tokens` table
  ship via migrations — `php artisan migrate --force`.
- Turn the gateway on with `CHAT_GATEWAY_ENABLED=true`, then
  `php artisan optimize` (config is cached; see [DEPLOYMENT.md](DEPLOYMENT.md)).
- Nginx must **not buffer** the `/llm` SSE stream. The gateway already sends
  `X-Accel-Buffering: no` + `Cache-Control: no-transform`; the existing SSE
  nginx config in [DEPLOYMENT.md](DEPLOYMENT.md) covers it.
- The local `php artisan serve` dev server is single-worker and will stall on
  Claude Code's streaming + parallel requests — run it with
  `PHP_CLI_SERVER_WORKERS=10` (or test against the real nginx + PHP-FPM host).

---

## 9. Files

| File | Role |
| ---- | ---- |
| `routes/gateway.php` | The `llm/v1` routes (no web session/CSRF) |
| `bootstrap/app.php` | Registers the group + `GatewayAuth`; adds `llm/*` to JSON rendering |
| `app/Http/Middleware/GatewayAuth.php` | Token → user; Anthropic-style 401; enable check |
| `app/Http/Controllers/GatewayController.php` | Budget gate (429), raw-body forward |
| `app/Services/AnthropicGateway.php` | Model pin, upstream forward, stream relay, usage record, request log |
| `app/Services/SseUsageParser.php` | Tallies token usage (input/output split) from the SSE stream |
| `app/Services/AnthropicRateLimits.php` | Captures rate-limit headers into a short-TTL cache (Analytics) |
| `app/Models/RequestLog.php` | Per-request log row (Analytics → Logs) |
| `app/Models/GatewayToken.php` | Hashed tokens: `issue()`, `findActive()`, `hash()` |
| `app/Http/Controllers/Settings/GatewayTokenController.php` | Developer access page + token CRUD |
| `resources/js/pages/settings/DeveloperAccess.vue` | The setup UI |

See [features.md](features.md) for the user-facing summary and
[changelog.md](changelog.md) for the change history.
