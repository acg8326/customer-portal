# Security

Every security control in the portal, where it lives, and how to tune it.
Layers are listed outside-in: transport/headers → auth → data → AI/tool safety
→ abuse limits.

## Response headers (defense-in-depth)

[`SecurityHeaders`](../app/Http/Middleware/SecurityHeaders.php) middleware adds
to every web response:

| Header | Value | Stops |
| --- | --- | --- |
| `Content-Security-Policy` | `default-src 'self'` (+ scoped allowances) | injected scripts loading/executing, even if sanitization ever missed |
| `X-Frame-Options` / `frame-ancestors` | `DENY` / `'none'` | clickjacking |
| `X-Content-Type-Options` | `nosniff` | MIME-sniffing uploads into scripts |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | URL leakage to other sites |
| `Permissions-Policy` | camera/mic/geolocation off | permission abuse |

Config: `config/security.php` → `SECURITY_HEADERS`, `SECURITY_CSP`,
`SECURITY_CSP_POLICY` (override the whole policy string if you add a CDN).

The CSP is environment-aware:

- **Production** (built assets): always sent, with a per-request **nonce**
  (`Vite::useCspNonce()`) added to `script-src` so the layout's inline
  theme-init script runs while `script-src` stays strict — no
  `'unsafe-inline'` scripts.
- **Local dev** (`npm run dev` running, `public/hot` exists): the header is
  **not sent**. Browsers reject IPv6 sources like `[::1]` in CSP lists —
  Chrome logs "contains an invalid source … It will be ignored" — and Vite
  often binds `[::1]`, so the dev origin can't reliably be whitelisted; dev
  tooling (Vite HMR, Laravel Boost's browser logger) also injects nonce-less
  inline scripts. A CSP on localhost adds no protection and only breaks the
  UI. The other headers are still sent in dev.

## Authentication & authorization

- **Login-only** (Fortify) — no public registration; admins create members at
  `/users`. 2FA (TOTP) and **passkeys** available under Settings → Security.
- Roles: `admin` / `user` (column on `users`); admin routes behind the `admin`
  middleware; everything else behind `auth` (+ `verified`).
- **Per-resource ownership** is enforced everywhere: conversations, messages
  (feedback), projects, skills, MCP servers, NetSuite connections, and the
  tool-decision endpoint all 404 on another user's resource.
- Session cookies encrypted; CSRF on all POSTs (the chat sends `X-XSRF-TOKEN`).

## Secrets & data at rest

- All third-party credentials are **encrypted at rest** with Eloquent
  `encrypted` casts and `Hidden` from serialization:
  - NetSuite: TBA consumer/token keys, OAuth2 client id/secret, access/refresh
    tokens ([`NetsuiteConnection`](../app/Models/NetsuiteConnection.php));
  - MCP: auth tokens, OAuth client secrets/tokens/metadata;
  - Webhooks: the whole per-user config (URL + shared secret);
  - The **paused tool-loop state** (`conversations.pending_tool_state`) — it
    carries tool inputs/results.
- `ANTHROPIC_API_KEY` / `COMPOSIO_API_KEY` are server-side only; `.env` is
  gitignored and never deployed by the repo.

## Model output & input hardening

- Assistant Markdown → **DOMPurify** before rendering (`v-html` never gets raw
  model output). The CSP above is the second net.
- PDF export escapes raw HTML (`html_input: escape`) and dompdf runs with
  `isRemoteEnabled=false` (no fetch-a-URL-into-a-PDF SSRF). DOCX/XLSX writers
  XML-escape everything.
- SuiteQL record type/id inputs are regex-sanitized; LIKE wildcards escaped in
  search; every endpoint uses validation (model ids against the allowlist).
- Uploads: extension + size validated, and `mimes:` content-sniffs the real
  type. Optional **ClamAV scanning** (`SECURITY_UPLOAD_SCAN=true`,
  [`UploadScanner`](../app/Services/UploadScanner.php)) — **fail-closed**: if
  scanning is enabled but the scanner is missing/broken, uploads are rejected,
  never silently unscanned.

## AI / tool safety (the layer unique to this app)

Three mechanisms, strongest first:

1. **Hard approval gate** (`ANTHROPIC_TOOL_HARD_GATE`, default on). In the
   connected-tools loop (Composio + NetSuite), a **destructive tool call
   pauses the turn before executing**: the loop state is persisted (encrypted)
   and the chat shows an **Approve & run / Cancel** card listing each call and
   its exact input. Nothing runs until Approve; Cancel ends the turn with a
   note and runs nothing. The pending state is consumed exactly once (a double
   click can't double-run), a new message supersedes it, and messages already
   compacted can't be touched. "Destructive" = the tool name contains a
   configured verb token (`ANTHROPIC_TOOL_GATE_VERBS`: create/update/delete/
   send/…) — reads (get/list/search/suiteql) never gate.
2. **Ask-in-text guardrail** (`ANTHROPIC_TOOL_SAFETY`). For **MCP servers** —
   which execute at Anthropic and can't be gated client-side — the system
   prompt requires the model to describe and confirm destructive actions in
   text first. When the hard gate is on it replaces this for Composio/NetSuite
   so users aren't asked twice.
3. **Prompt-injection defense** (`ANTHROPIC_TOOL_USE_PROMPT`, always on with
   any tools): content returned by tools/web/files is **data, not
   instructions** — embedded commands must not be followed. Deliberately NOT
   skipped by auto-approve.

**Auto-approve** (per-session toggle, confirmation dialog) bypasses 1 and 2 —
by explicit user choice — but never 3. The real backstop for connected systems
remains **least privilege**: read-only NetSuite roles/tokens, minimal OAuth
scopes (`MCP_OAUTH_SCOPES`).

## Abuse limits

- Per-user **rate limits** on every chat/integration route
  (`RATE_LIMIT_CHAT/SEARCH/INTEGRATIONS/INTEGRATION_TEST`, per minute).
- Per-user **token budgets** (`USAGE_TOKEN_LIMIT`, rolling
  `USAGE_PERIOD_DAYS`) hard-block chat when exhausted; 0 = track only.
- Tool results capped (`ANTHROPIC_TOOL_RESULT_MAX_CHARS`), SuiteQL rows capped
  (`NETSUITE_SUITEQL_MAX_ROWS`) — a hostile/huge payload can't blow up a turn.

## Data retention

Retention is **opt-in** (`config/retention.php`): by default chats are kept
until their owner deletes them. Setting `RETENTION_CHAT_DAYS` hard-deletes
conversations (messages + stored attachments) idle longer than that, via the
daily `chat:prune` schedule; soft-deleted (trashed) records purge for good
after `RETENTION_TRASH_DAYS` (default 30). Users can always delete their own
chats immediately. Messages are also sent to Anthropic's API at generation
time under Anthropic's data policies.

## Known limitations

- MCP tool calls execute server-side at Anthropic — for them the guardrail is
  policy, not a hard gate. Use least-privilege scopes.
- The destructive-verb classifier is name-based; a tool whose name hides its
  effect (rare in Composio's naming) could misclassify. Add verbs to
  `ANTHROPIC_TOOL_GATE_VERBS` as new toolkits are connected.
- No WAF/IDS at the app layer — assumed to live at the host/proxy.
