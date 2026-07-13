# NetSuite integration — setup & troubleshooting

The portal talks to NetSuite **natively** over SuiteTalk REST + SuiteQL — no
middleman. (We deliberately do NOT use Composio's NetSuite toolkit: it is
OAuth 2.0-only and its tokens 401 with INVALID_LOGIN on record reads.) Two auth
methods are supported; each user connects their own account under
**Integrations → NetSuite**.

## Which auth method?

| | Token-Based Auth (TBA) | OAuth 2.0 (Authorization Code) |
| --- | --- | --- |
| Style | Five values pasted once | Click → consent on NetSuite → done |
| Protocol | OAuth 1.0a request signing (HMAC-SHA256) | Bearer tokens + refresh |
| Expiry | Tokens don't expire (revoke manually) | Access token expires; auto-refreshed |
| Best for | Server-to-server, set-and-forget | Accounts standardizing on OAuth 2.0 |

Both end at the same REST APIs with the same role permissions.

## NetSuite-side setup (admin, once per account)

1. **Enable features** (Setup → Company → Enable Features → SuiteCloud):
   REST Web Services, plus **Token-Based Authentication** (for TBA) and/or
   **OAuth 2.0** (for the OAuth flow).
2. **Integration record** (Setup → Integration → Manage Integrations → New):
   - TBA: tick **Token-Based Authentication**. Save → copy the **Consumer
     Key / Consumer Secret** (shown once).
   - OAuth 2.0: tick **Authorization Code Grant**, scope `REST Web Services`,
     and set the **Redirect URI** to exactly
     `https://<your-portal-domain>/integrations/netsuite/callback`.
     Save → copy the **Client ID / Client Secret**.
3. **Role** for the connecting user, with at minimum:
   - Permissions → Setup: **REST Web Services**, **Log in using Access
     Tokens** (TBA) / **OAuth 2.0 Authorized Applications** (OAuth);
   - Permissions → Lists/Transactions: every record type the assistant should
     read (Customers, Contacts, Invoices, …).
4. **Access token** (TBA only — Setup → Users/Roles → Access Tokens → New):
   pick the integration record + user + role → copy **Token ID / Token
   Secret** (shown once).

## Portal-side setup (each user)

Integrations → NetSuite → Connect:

- **TBA:** paste Account ID (e.g. `1234567` or `1234567_SB1`), Consumer
  Key/Secret, Token ID/Secret → **Connect** runs a live test immediately.
- **OAuth 2.0:** paste Account ID + Client ID/Secret → you're redirected to
  NetSuite's consent page → approve → back to the portal, connected.

Then just ask AiMe: *"show me the 5 newest customers in NetSuite"*. The
assistant has two tools: `netsuite_suiteql` (query anything) and
`netsuite_get_record` (fetch one record) — both **read-only**.

## Troubleshooting

| Symptom | Cause → fix |
| --- | --- |
| `INVALID_LOGIN` on connect (TBA) | Wrong Account ID (sandboxes need the `_SB1` suffix), or key/secret pairs swapped, or the token's role lacks **Log in using Access Tokens**. Check Setup → Users/Roles → View Login Audit Trail for the exact detail. |
| "Your current role does not have permission to perform this action" on **every** call, even though permissions look right | Role config issue on the NetSuite side: the REST Web Services permission wasn't saved, or the role has a **Restrictions** subtab limiting it, or the token was minted against a different role. Re-save the role, check Restrictions, or mint a token with an Administrator role to isolate. |
| SuiteQL works but a record type 404s / permission-errors | The role has REST Web Services but not that record's List/Transaction permission — add it. |
| OAuth consent returns an error | Redirect URI mismatch: it must match the integration record **exactly** (scheme + host + path) and `APP_URL` must be the real domain. |
| Connected but tools "aren't available" in chat | `NETSUITE_ENABLED=false`, or toolkit routing didn't match — mention "netsuite" in the message, or adjust `NETSUITE_KEYWORDS`. |

The host is derived from the account id: `1234567_SB1` →
`1234567-sb1.suitetalk.api.netsuite.com` (lowercased, `_`→`-`).

## Security notes

- All five TBA values, OAuth client credentials, and issued tokens are
  **encrypted at rest**; nothing is ever sent to the browser after connect.
- TBA requests are signed per-request (HMAC-SHA256, fresh nonce/timestamp);
  OAuth tokens auto-refresh `NETSUITE_OAUTH_REFRESH_LEEWAY` seconds early.
- The assistant's NetSuite tools are read-only, so the destructive-action
  gate never applies to them — least privilege still matters: give the token's
  role only the record permissions you want readable in chat.

## Config reference (.env)

`NETSUITE_ENABLED`, `NETSUITE_TIMEOUT`, `NETSUITE_SUITEQL_MAX_ROWS`,
`NETSUITE_REST_DOMAIN`, `NETSUITE_APP_DOMAIN`, `NETSUITE_OAUTH_SCOPES`,
`NETSUITE_OAUTH_REDIRECT`, `NETSUITE_OAUTH_REFRESH_LEEWAY`,
`NETSUITE_KEYWORDS` — defaults in [`config/services.php`](../config/services.php).
