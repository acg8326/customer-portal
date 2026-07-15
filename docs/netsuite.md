# NetSuite integration — setup & troubleshooting

The portal talks to NetSuite **natively** over SuiteTalk REST + SuiteQL — no
middleman. (We deliberately do NOT use Composio's NetSuite toolkit — its
tokens 401 with INVALID_LOGIN on record reads. That also means third-party
guides showing a `backend.composio.dev` redirect URI do **not** apply here.)

Authentication is **OAuth 2.0 (Authorization Code Grant)**: paste the
integration record's Client ID/Secret once, approve on NetSuite's consent
screen, done. Tokens auto-refresh.

> **Legacy TBA:** connections made earlier over Token-Based Auth keep working
> (the signing code is retained server-side), but the UI now offers OAuth 2.0
> only.

## NetSuite-side setup (admin, once per account)

1. **Enable features** — Setup → Company → Enable Features → **SuiteCloud**
   tab, check all of:
   - **Client SuiteScript**
   - **Server SuiteScript**
   - **REST Web Services**
   - **OAuth 2.0**

   Save.
2. **Create the integration record** — Setup → Integration → Manage
   Integrations → **New**:
   - **Name:** `CWGP-AIMe`
   - Under **OAuth 2.0**: check **Authorization Code Grant**
   - **Scope:** tick **REST Web Services** and **RESTlets** (the portal
     requests only `rest_webservices` by default — see
     `NETSUITE_OAUTH_SCOPES` — but the record may allow more than is
     requested, and ticking RESTlets avoids a re-consent later)
   - **Redirect URI** — exactly:

     ```
     https://aime.cwglobal.ai/integrations/netsuite/callback
     ```

     (character-for-character — scheme, host, path, no trailing slash; it's
     derived from `APP_URL`, overridable via `NETSUITE_OAUTH_REDIRECT`). The
     in-app setup guide and Connect dialog display the server's actual
     configured value, so what users see always matches what the OAuth flow
     sends.
3. **Save → copy the Client ID and Client Secret immediately.**
   ⚠️ **The Client Secret is shown only once.** If you lose it, reset the
   credentials on the integration record (Reset Credentials) and update the
   portal connection.
4. **Role/permissions** for the user who will approve access:
   - Permissions → Setup: **REST Web Services**, **OAuth 2.0 Authorized
     Applications**;
   - Permissions → Lists/Transactions: every record type the assistant should
     read (Customers, Contacts, Invoices, …).

## Portal-side setup (each user)

Integrations → NetSuite → **Connect**:

1. Paste **Account ID** (Setup → Company → Company Information — e.g.
   `1234567`, or `1234567_SB1` for a sandbox) + **Client ID / Client Secret**.
2. Click **Continue to NetSuite** → approve on the consent screen → you land
   back on the Integrations page, connected.

Then just ask AiMe: *"show me the 5 newest customers in NetSuite"*. The
assistant has two tools: `netsuite_suiteql` (query anything) and
`netsuite_get_record` (fetch one record) — both **read-only**.

## Troubleshooting

| Symptom | Cause → fix |
| --- | --- |
| Consent screen never appears / NetSuite shows an integration error | **Redirect URI mismatch** — it must match the integration record exactly (`https://aime.cwglobal.ai/integrations/netsuite/callback`), and `APP_URL` on the server must be the real domain (re-run `php artisan optimize` after changing it). |
| "invalid_client" on the consent page | Wrong Client ID, or the integration record isn't saved with Authorization Code Grant checked. |
| Approved on NetSuite but the portal shows an error on return | Session lost across the redirect (check `SESSION_DRIVER`), or the Client Secret is wrong — the code-for-token exchange failed. |
| "Your current role does not have permission to perform this action" on every call | The approving user's role lacks **REST Web Services** / **OAuth 2.0 Authorized Applications**, or has a **Restrictions** subtab limiting it. Re-check the role, or test with an Administrator role to isolate. |
| SuiteQL works but a record type 404s / permission-errors | The role has REST Web Services but not that record's List/Transaction permission — add it. |
| Connected but tools "aren't available" in chat | `NETSUITE_ENABLED=false`, or toolkit routing didn't match — mention "netsuite" in the message, or adjust `NETSUITE_KEYWORDS`. |

The host is derived from the account id: `1234567_SB1` →
`1234567-sb1.suitetalk.api.netsuite.com` (lowercased, `_`→`-`).

## Security notes

- OAuth client credentials and issued tokens are **encrypted at rest**;
  nothing is ever sent to the browser after connect.
- Access tokens auto-refresh `NETSUITE_OAUTH_REFRESH_LEEWAY` seconds early.
- The assistant's NetSuite tools are read-only, so the destructive-action
  gate never applies to them — least privilege still matters: give the
  approving role only the record permissions you want readable in chat.

## Config reference (.env)

`NETSUITE_ENABLED`, `NETSUITE_TIMEOUT`, `NETSUITE_SUITEQL_MAX_ROWS`,
`NETSUITE_REST_DOMAIN`, `NETSUITE_APP_DOMAIN`, `NETSUITE_OAUTH_SCOPES`,
`NETSUITE_OAUTH_REDIRECT`, `NETSUITE_OAUTH_REFRESH_LEEWAY`,
`NETSUITE_KEYWORDS` — defaults in [`config/services.php`](../config/services.php).
