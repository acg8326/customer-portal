# Composio integrations — reference & playbook

How AiMe connects apps (Slack, GitHub, HubSpot, Airtable, …) through
**Composio**, the hard-won API quirks, and the exact steps to add or debug a
toolkit. Read this before wiring a new Composio integration — it will save hours.

> **NetSuite is no longer a Composio integration.** Composio's NetSuite toolkit
> is OAuth 2.0 only, and those tokens 401 (`INVALID_LOGIN`) on record reads.
> NetSuite is now a **native Token-Based Auth (TBA / OAuth 1.0a)** integration —
> see [`NetsuiteService`](../app/Services/NetsuiteService.php) and
> [features.md](features.md). The NetSuite notes below are kept only as a record
> of the OAuth 2.0 investigation.

Code: [`ComposioService`](../app/Services/ComposioService.php),
[`ComposioController`](../app/Http/Controllers/ComposioController.php),
config in [`config/services.php`](../config/services.php) (`services.composio`),
UI in [`Integrations.vue`](../resources/js/pages/Integrations.vue).

---

## 1. The big picture

Composio is a hosted **tool gateway**: it owns the OAuth apps for 250+ SaaS
tools, so users authorize *Composio* (not us) and we reach tools through **one
Composio API key** + a per-user OAuth grant.

**We do NOT use Anthropic's MCP connector for Composio.** Composio's MCP endpoint
requires an `x-api-key` header (or a JWT bearer), and Anthropic's server-side MCP
connector only sends `Authorization: Bearer …` with no custom headers — verified
incompatible (x-api-key → 200; Bearer → 401 "valid JWT Bearer token required").

Instead we run a **client-side tool loop** ("Path B"):

1. On each chat turn, for every toolkit the user has actively connected, we fetch
   Composio's tool schemas and hand them to Claude as normal `tools`.
2. When Claude emits `tool_use`, we execute each call **server-side** via
   Composio's REST API (`POST /api/v3/tools/execute/{slug}`) with our `x-api-key`.
3. We feed results back and loop until Claude stops (capped by
   `COMPOSIO_MAX_TOOL_ROUNDS`).

Connections are **per user**: each AiMe user maps to a Composio `user_id` (we use
the AiMe user id), so every person's chat acts as their own connected account.

---

## 2. Two toolkit modes

Configured in `config/services.php` → `services.composio.toolkits`.

### `managed` (default) — Composio owns the OAuth app
One-click connect. Needs a pre-created **auth-config id** (`ac_…`) from the
Composio dashboard. Examples: **Slack, GitHub, HubSpot, Airtable**.

```php
'slack' => [
    'name' => 'Slack',
    'auth_config_id' => env('COMPOSIO_SLACK_AUTH_CONFIG'),
],
```

Connect flow: `GET /integrations/composio/{toolkit}/connect` →
`initiateLink()` → redirect the user to Composio consent.

### `credentials` — bring-your-own OAuth app
The user pastes **their own** OAuth app's Client ID/Secret (+ any account
identifier), and we **create the Composio auth config on the fly**. Needed when
the OAuth app is per-account and can't be shared. Example: **NetSuite**
(integration records are per-account).

```php
'netsuite' => [
    'name' => 'NetSuite',
    'mode' => 'credentials',
    'auth_scheme' => 'OAUTH2',
    'scopes' => env('COMPOSIO_NETSUITE_SCOPES', 'restlets,rest_webservices'),
    'optional_scopes' => ['suite_analytics' => 'My NetSuite has SuiteAnalytics Connect enabled'],
    'credentials' => ['client_id' => 'Client ID', 'client_secret' => 'Client Secret'],
    'initiation' => ['subdomain' => 'Account ID'],  // → connection_data
],
```

- `credentials` — **secret** fields collected to CREATE the auth config.
- `initiation` — **non-secret** fields sent as `connection_data` (e.g. NetSuite
  `subdomain`).
- `optional_scopes` — checkboxes that append extra OAuth scopes.

Connect flow: `POST /integrations/composio/{toolkit}/connect` (secrets can't ride
a GET redirect) → `initiateWithCredentials()` creates the auth config, then links,
and returns `{redirect_url}` as JSON; the UI navigates to it.

---

## 3. ⚠️ Composio API quirks that cost us hours

These are the non-obvious things. **Trust this section over intuition.**

### 3a. `toolkit_versions=latest` is REQUIRED on the tools list
```
GET /api/v3/tools?toolkit_slug=netsuite                     → 0 tools  (!!)
GET /api/v3/tools?toolkit_slug=netsuite&toolkit_versions=latest → 86 tools
```
Some toolkits (NetSuite) resolve their *default* version to an empty set. Slack
didn't need it, so it's easy to miss. **Always pass `toolkit_versions=latest`.**

### 3b. `important=true` is unreliable per-toolkit — merge, don't rely on it
`important=true` returns a curated subset. Quality varies wildly:
- **Slack**: great — surfaces `send`/`search`/`list` (late-alphabet tools that a
  plain `limit` would cut).
- **NetSuite**: bad — only **5** tools, all create/OAuth, **zero reads** (so you
  couldn't list customers).

Our fix: **two passes per toolkit — the `important` set PLUS the general list —
deduped and capped** at `COMPOSIO_MAX_TOOLS`. Best of both: curated high-value +
breadth.

### 3c. Only the SINGULAR `toolkit_slug` filters; plural forms are IGNORED
```
?toolkit_slug=netsuite   → filters to NetSuite (correct)
?toolkits=netsuite       → IGNORED — returns ALL toolkits alphabetically
?toolkit_slugs=netsuite  → IGNORED — returns ALL toolkits alphabetically
```
If a filter "works" but returns a suspiciously round number (100) of unrelated
tools (`_1PASSWORD_*`, `ABLY_*`, …), the param was ignored. Always eyeball the
returned slugs' prefixes.

### 3d. Redirect URI for custom OAuth apps
For `credentials` toolkits, register this exact Redirect URI in the provider's
OAuth app (Composio's dashboard shows the same value):
```
https://backend.composio.dev/api/v1/auth-apps/add
```
An `ACTIVE` connection proves the redirect URI was correct — it is unrelated to
whether tools load (that's §3a/§3b).

### 3e. "Connected" ≠ "has tools"
A grant can be `ACTIVE` while the tools list returns 0 (see §3a). When debugging
"AiMe says it can't access X", check the **tools list** separately from the
**connection status** — they're independent.

### 3f. Auth-config creation response nests the id
`POST /api/v3/auth_configs` returns `{ toolkit, auth_config: { id: "ac_…" } }` —
the new id is at **`auth_config.id`**, not top-level.

### 3g. `execute` ALSO needs a version (defaults to an empty one)
Same trap as §3a but on the execute endpoint: `POST /tools/execute/{slug}`
defaults `version` to `00000000_00`, which 404s ("Tool … not found") for
versioned toolkits (NetSuite). **Send `version: "latest"`** (we use
`services.composio.tool_version`) — and it should match the version you listed
the schema with, so the args line up.

### 3h. Duplicate connected accounts → wrong token on execute
`execute` by `user_id` alone is ambiguous when a user has **more than one**
active account for a toolkit (easy to accumulate: our credentials flow creates a
fresh auth config per connect, and reconnecting/credential-resets add more).
Composio may then use a **stale** account → `INVALID_LOGIN`. Fixes:
- We pin execute to the **`connected_account_id`** we recorded (plus `user_id`,
  which Composio requires alongside it).
- `disconnect()` best-effort deletes the remote account so reconnects start clean.
- Composio requires `user_id` **with** `connected_account_id` — sending the
  account id alone returns `ConnectedAccountEntityIdRequired` (400).
- Clean up leftover duplicates in the Composio dashboard (or via
  `DELETE /api/v3/connected_accounts/{id}`).

### 3i. NetSuite `INVALID_LOGIN` after a credential reset
If you **Reset Credentials** on the NetSuite integration record, tokens issued
under the old secret are revoked → SuiteQL/REST return 401 `INVALID_LOGIN`. Do a
**clean reconnect** with the current secret (disconnect first so the old grant is
removed), and don't reset again afterwards. NetSuite's *Setup → Users/Roles →
User Management → View Login Audit Trail* shows the precise reason.

---

## 4. Endpoints we use (all with header `x-api-key: <key>`, base `https://backend.composio.dev`)

| Purpose | Call |
|---|---|
| List tool schemas | `GET /api/v3/tools?toolkit_slug=X&toolkit_versions=latest[&important=true]&limit=N` |
| Create custom auth config | `POST /api/v3/auth_configs` `{toolkit:{slug}, auth_config:{type:"use_custom_auth", authScheme:"OAUTH2", name, credentials:{client_id, client_secret, scopes}}}` |
| Start a connection (link) | `POST /api/v3/connected_accounts/link` `{auth_config_id, user_id, callback_url, connection_data?}` → `{redirect_url, connected_account_id}` |
| Live connection status | `GET /api/v3/connected_accounts?user_ids=<id>&toolkit_slugs=<slug>` → items with `status` (ACTIVE/EXPIRED/INITIALIZING) |
| Execute a tool | `POST /api/v3/tools/execute/{slug}` `{user_id, arguments}` → `{successful, data, error}` |
| List auth configs | `GET /api/v3/auth_configs[?toolkit_slug=X]` |
| Toolkit metadata (auth fields, scopes, initiation fields) | `GET /api/v3/toolkits/{slug}` |

The callback we pass is `route('integrations.composio.callback', {toolkit})`. The
callback handler **verifies status is ACTIVE** before marking a card connected —
a returned redirect alone doesn't guarantee authorization.

Tool-name rule: Composio slugs double as the Anthropic tool name and must match
`^[a-zA-Z0-9_-]{1,64}$`; longer/odd slugs are skipped.

---

## 5. Add a NEW toolkit

### Managed (one-click, Composio-owned OAuth app)
1. In the Composio dashboard → **Auth Configs → Create** → pick the toolkit →
   (Composio manages the app) → copy the `ac_…` id.
2. Add a config entry + `.env` var:
   ```php
   'notion' => ['name' => 'Notion', 'auth_config_id' => env('COMPOSIO_NOTION_AUTH_CONFIG')],
   ```
   ```dotenv
   COMPOSIO_NOTION_AUTH_CONFIG=ac_xxxxxxxx
   ```
3. Add a card in `Integrations.vue` with `composio: 'notion'`.
4. Done — one-click connect works. No new backend code.

### Credentials (bring-your-own OAuth app)
1. Add a `mode => 'credentials'` config entry with `credentials`, `initiation`,
   `optional_scopes`, `scopes`, `auth_scheme` (see NetSuite in §2).
2. Add a card with `composio: '<key>'`. The UI auto-renders a credentials modal
   from the config-declared fields.
3. Tell users the Redirect URI to register (§3d) and which scopes to enable.
4. No new backend code — `initiateWithCredentials()` handles it generically.

**Both:** confirm tools actually load (§6) before declaring victory.

---

## 6. Troubleshooting playbook

Set `KEY=<your COMPOSIO_API_KEY>` first. Replace `<slug>`/`<uid>`.

**"AiMe says it can't access the tool / only has Slack"** → tools list is empty.
```bash
curl -s -H "x-api-key: $KEY" \
 "https://backend.composio.dev/api/v3/tools?toolkit_slug=<slug>&toolkit_versions=latest&limit=5" \
 | python3 -c "import sys,json;print(len(json.load(sys.stdin).get('items',[])))"
```
- 0 without `toolkit_versions=latest` but >0 with it → the version bug (§3a); our
  code already passes it, so make sure config cache is fresh (`php artisan config:clear`).
- 0 even with it → Composio genuinely isn't exposing that toolkit's tools for your
  plan/project; ask Composio support.

**Confirm the connection is really ACTIVE:**
```bash
curl -s -H "x-api-key: $KEY" \
 "https://backend.composio.dev/api/v3/connected_accounts?user_ids=<uid>&toolkit_slugs=<slug>"
```

**`scope_mismatch` on connect** → the scopes requested don't match the OAuth app.
For NetSuite, the SuiteAnalytics toggle must match the integration record's
scopes. Align `services.composio.toolkits.*.scopes` / the optional-scope tick.

**Redirect URI mismatch** → register exactly `https://backend.composio.dev/api/v1/auth-apps/add` (§3d).

**`INSUFFICIENT_PERMISSION` on a read** → the connected **role** lacks access to
that record type (NetSuite), not a tooling bug. Reconnect with a role that has it.

**Secret shown once (NetSuite/OAuth apps)** → editing the integration record won't
re-show the Client Secret. Use **Reset Credentials** to regenerate (invalidates
the old one → reconnect in AiMe with the new secret).

---

## 7. Config & env reference

`config/services.php` → `services.composio`:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `api_key` | `COMPOSIO_API_KEY` | — | App→Composio key. Absent ⇒ Composio disabled. |
| `base_url` | `COMPOSIO_BASE_URL` | `https://backend.composio.dev` | API base. |
| `max_tools` | `COMPOSIO_MAX_TOOLS` | `100` | Max tool schemas per toolkit per turn (token/latency guard). |
| `max_tool_rounds` | `COMPOSIO_MAX_TOOL_ROUNDS` | `8` | Max tool-call rounds before we stop the loop. |
| `toolkits.<key>.auth_config_id` | `COMPOSIO_<KEY>_AUTH_CONFIG` | — | Managed toolkits only. |
| `toolkits.netsuite.scopes` | `COMPOSIO_NETSUITE_SCOPES` | `restlets,rest_webservices` | NetSuite OAuth scopes. |

**Security:** the API key is app-level and never reaches the browser. Per-user
OAuth grants/tokens live inside Composio. `x-api-key` is sent server-side only.
Rotate the key in the Composio dashboard if it's ever exposed.

---

## 8. Reference: tool counts (as observed)

`GET /api/v3/tools?toolkit_slug=X&toolkit_versions=latest` totals:
github ≈ 893 · hubspot ≈ 245 · slack ≈ 167 · netsuite ≈ 86 · airtable ≈ 26.
Because some toolkits have hundreds of tools, we cap at `COMPOSIO_MAX_TOOLS` and
prioritise the curated (`important`) set first (§3b).
