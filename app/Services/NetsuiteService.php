<?php

namespace App\Services;

use App\Models\NetsuiteConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Native NetSuite integration over Token-Based Authentication (TBA).
 *
 * NetSuite's recommended server-to-server auth is OAuth 1.0a "Token-Based
 * Authentication": every request carries an `Authorization: OAuth …` header
 * whose signature is an HMAC-SHA256 of the request, keyed by the consumer +
 * token secrets. This service signs and sends requests to SuiteTalk REST and
 * SuiteQL, and exposes a small set of tools Claude can call in chat.
 *
 * (This deliberately bypasses Composio — its NetSuite toolkit is OAuth 2.0 only,
 * and those tokens can't reliably read records.)
 */
class NetsuiteService
{
    /** Prefix that marks a Claude tool as belonging to NetSuite. */
    public const TOOL_PREFIX = 'netsuite_';

    public function enabled(): bool
    {
        return (bool) config('services.netsuite.enabled', false);
    }

    public function connectionFor(User $user): ?NetsuiteConnection
    {
        return $user->netsuiteConnection;
    }

    /**
     * Whether this user has a usable NetSuite connection (feature on + active
     * connection stored).
     */
    public function enabledFor(User $user): bool
    {
        return $this->enabled()
            && ($conn = $this->connectionFor($user)) !== null
            && $conn->isActive();
    }

    /**
     * Store (or replace) a user's TBA credentials. Secrets are encrypted by the
     * model's casts.
     *
     * @param  array{account_id: string, consumer_key: string, consumer_secret: string, token_id: string, token_secret: string}  $creds
     */
    public function store(User $user, array $creds): NetsuiteConnection
    {
        $conn = $user->netsuiteConnection ?? new NetsuiteConnection;
        $conn->user_id = $user->id;
        $conn->account_id = trim($creds['account_id']);
        $conn->auth_type = NetsuiteConnection::AUTH_TBA;
        $conn->consumer_key = trim($creds['consumer_key']);
        $conn->consumer_secret = trim($creds['consumer_secret']);
        $conn->token_id = trim($creds['token_id']);
        $conn->token_secret = trim($creds['token_secret']);
        // Clear any OAuth2 state from a previous connection of the same user.
        $conn->client_id = null;
        $conn->client_secret = null;
        $conn->access_token = null;
        $conn->refresh_token = null;
        $conn->token_expires_at = null;
        $conn->status = 'active';
        $conn->last_error = null;
        $conn->save();

        return $conn;
    }

    /**
     * Store the OAuth 2.0 app credentials and start the consent flow. The
     * connection is saved as 'pending' until the callback exchanges the code
     * for tokens. Returns [connection, authorizeUrl, state].
     *
     * @param  array{account_id: string, client_id: string, client_secret: string}  $creds
     * @return array{0: NetsuiteConnection, 1: string, 2: string}
     */
    public function beginOauth(User $user, array $creds, string $state): array
    {
        $conn = $user->netsuiteConnection ?? new NetsuiteConnection;
        $conn->user_id = $user->id;
        $conn->account_id = trim($creds['account_id']);
        $conn->auth_type = NetsuiteConnection::AUTH_OAUTH2;
        $conn->client_id = trim($creds['client_id']);
        $conn->client_secret = trim($creds['client_secret']);
        // Clear any TBA / previously-issued tokens.
        $conn->consumer_key = null;
        $conn->consumer_secret = null;
        $conn->token_id = null;
        $conn->token_secret = null;
        $conn->access_token = null;
        $conn->refresh_token = null;
        $conn->token_expires_at = null;
        $conn->status = 'pending';
        $conn->last_error = null;
        $conn->save();

        return [$conn, $this->authorizeUrl($conn, $state), $state];
    }

    public function disconnect(User $user): void
    {
        $user->netsuiteConnection?->delete();
    }

    /**
     * Verify the stored credentials by running a tiny SuiteQL query. Returns a
     * human-readable result either way; on an auth failure the connection is
     * flagged so the UI can show it needs attention.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(NetsuiteConnection $conn): array
    {
        try {
            [$status, $body] = $this->suiteqlRaw($conn, 'SELECT id FROM customer', 1, 0);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'Could not reach NetSuite: '.$e->getMessage()];
        }

        if ($status >= 200 && $status < 300) {
            $conn->forceFill(['status' => 'active', 'last_error' => null, 'last_used_at' => now()])->save();

            return ['ok' => true, 'message' => 'Connected — NetSuite accepted the token and returned data.'];
        }

        $detail = $this->errorDetail($body);

        // An auth/login failure means the token itself is bad. Any *other* error
        // (e.g. a permission or field error) still proves the credentials are
        // valid — NetSuite authenticated us before rejecting the query.
        $isAuthError = $status === 401
            || str_contains(strtoupper($detail), 'INVALID_LOGIN')
            || str_contains(strtoupper($detail), 'INVALID LOGIN');

        if ($isAuthError) {
            $conn->forceFill(['status' => 'error', 'last_error' => $detail])->save();

            return ['ok' => false, 'message' => 'NetSuite rejected the token ('.$detail.'). Double-check the Account ID, Consumer Key/Secret, and Token ID/Secret, and that the token\'s role has "Log in using Access Tokens" + REST Web Services.'];
        }

        // Authenticated, but this specific query was refused — credentials work.
        $conn->forceFill(['status' => 'active', 'last_error' => null, 'last_used_at' => now()])->save();

        return ['ok' => true, 'message' => 'Token accepted (authenticated), but the test query returned: '.$detail.'. The connection is saved — grant the role record permissions if you need that data.'];
    }

    /**
     * Run a SuiteQL query and return decoded rows (throws on transport error;
     * returns the NetSuite error payload as-is on an API error).
     *
     * @return array<string, mixed>
     */
    public function suiteql(NetsuiteConnection $conn, string $query, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min($limit, (int) config('services.netsuite.suiteql_max_rows', 100)));
        [$status, $body] = $this->suiteqlRaw($conn, $query, $limit, $offset);

        if ($status >= 200 && $status < 300) {
            $conn->forceFill(['last_used_at' => now()])->save();
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * GET a single record by internal id, e.g. record_type "customer".
     *
     * @return array<string, mixed>
     */
    public function getRecord(NetsuiteConnection $conn, string $recordType, string $id): array
    {
        $recordType = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $recordType) ?? '');
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';
        $url = $this->baseUrl($conn)."/services/rest/record/v1/{$recordType}/{$id}";

        [$status, $body] = $this->send($conn, 'GET', $url);

        if ($status >= 200 && $status < 300) {
            $conn->forceFill(['last_used_at' => now()])->save();
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * The NetSuite tools exposed to Claude. Names are prefixed so the chat loop
     * can route execution here (vs. Composio).
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function toolSchemas(): array
    {
        return [
            [
                'name' => self::TOOL_PREFIX.'suiteql',
                'description' => 'Run a read-only SuiteQL (SQL) query against NetSuite and return the rows. '
                    .'Use this for listing or searching records — customers, invoices, transactions, items, etc. '
                    .'Example: "SELECT id, entityid, companyname FROM customer". Do not include a trailing '
                    .'semicolon. Results are capped, so add your own filters/ordering for large tables.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The SuiteQL SELECT statement to run.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max rows to return (optional; capped by the server).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => self::TOOL_PREFIX.'get_record',
                'description' => 'Fetch a single NetSuite record by its internal id via the REST record API. '
                    .'Provide the record type (e.g. "customer", "invoice", "salesorder") and the numeric id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'record_type' => [
                            'type' => 'string',
                            'description' => 'The record type, lower-case (e.g. customer, invoice).',
                        ],
                        'id' => [
                            'type' => 'string',
                            'description' => 'The internal id of the record.',
                        ],
                    ],
                    'required' => ['record_type', 'id'],
                ],
            ],
        ];
    }

    public function isNetsuiteTool(string $name): bool
    {
        return str_starts_with($name, self::TOOL_PREFIX);
    }

    /**
     * Execute one NetSuite tool call for a user and return a result for Claude.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, output: mixed}
     */
    public function execute(User $user, string $toolName, array $input): array
    {
        $conn = $this->connectionFor($user);

        if ($conn === null || ! $conn->isActive()) {
            return ['ok' => false, 'output' => 'No active NetSuite connection for this user.'];
        }

        try {
            $result = match ($toolName) {
                self::TOOL_PREFIX.'suiteql' => $this->suiteql(
                    $conn,
                    (string) ($input['query'] ?? ''),
                    (int) ($input['limit'] ?? config('services.netsuite.suiteql_max_rows', 100)),
                ),
                self::TOOL_PREFIX.'get_record' => $this->getRecord(
                    $conn,
                    (string) ($input['record_type'] ?? ''),
                    (string) ($input['id'] ?? ''),
                ),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'output' => 'NetSuite request failed: '.$e->getMessage()];
        }

        if ($result === null) {
            return ['ok' => false, 'output' => "Unknown NetSuite tool: {$toolName}."];
        }

        $status = (int) $result['status'];
        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'output' => $ok ? $result['body'] : ('NetSuite error (HTTP '.$status.'): '.$this->errorDetail($result['body'])),
        ];
    }

    // --- internals -------------------------------------------------------

    /**
     * @return array{0: int, 1: array<string, mixed>} [status, decoded body]
     */
    private function suiteqlRaw(NetsuiteConnection $conn, string $query, int $limit, int $offset): array
    {
        $query = trim(rtrim(trim($query), ';'));
        $url = $this->baseUrl($conn).'/services/rest/query/v1/suiteql?limit='.$limit.'&offset='.$offset;

        return $this->send($conn, 'POST', $url, ['q' => $query], ['Prefer' => 'transient']);
    }

    /**
     * Sign and send a request. Query-string params in $url are folded into the
     * OAuth signature (required); the JSON body is not (per OAuth 1.0a).
     *
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $extraHeaders
     * @return array{0: int, 1: array<string, mixed>} [status, decoded body]
     */
    private function send(NetsuiteConnection $conn, string $method, string $url, ?array $json = null, array $extraHeaders = []): array
    {
        // TBA signs each request (OAuth 1.0a); OAuth2 sends a Bearer token,
        // refreshing it first if it's expired.
        $authorization = $conn->isOauth2()
            ? 'Bearer '.$this->accessTokenFor($conn)
            : $this->authHeader($conn, $method, $url);

        $headers = array_merge([
            'Authorization' => $authorization,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $extraHeaders);

        $request = Http::withHeaders($headers)
            ->timeout((int) config('services.netsuite.timeout', 30));

        $response = $json !== null
            ? $request->send($method, $url, ['json' => $json])
            : $request->send($method, $url);

        $decoded = $response->json();

        return [$response->status(), is_array($decoded) ? $decoded : ['raw' => $response->body()]];
    }

    // --- OAuth 2.0 (Authorization Code Grant) ---------------------------

    /**
     * The redirect URI NetSuite sends the user back to after consent. Must match
     * the integration record's Redirect URI exactly.
     */
    public function redirectUri(): string
    {
        $configured = config('services.netsuite.oauth_redirect');

        return is_string($configured) && $configured !== ''
            ? $configured
            : rtrim((string) config('app.url'), '/').'/integrations/netsuite/callback';
    }

    /**
     * The consent URL to send the user's browser to.
     */
    public function authorizeUrl(NetsuiteConnection $conn, string $state): string
    {
        $host = $this->accountHost($conn->account_id);
        $domain = (string) config('services.netsuite.app_domain', 'app.netsuite.com');

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $conn->client_id,
            'redirect_uri' => $this->redirectUri(),
            'scope' => (string) config('services.netsuite.oauth_scopes', 'rest_webservices'),
            'state' => $state,
        ]);

        return "https://{$host}.{$domain}/app/login/oauth2/authorize.nl?".$params;
    }

    /**
     * Exchange an authorization code for access + refresh tokens and activate
     * the connection. Returns a human-readable result.
     *
     * @return array{ok: bool, message: string}
     */
    public function exchangeCode(NetsuiteConnection $conn, string $code): array
    {
        try {
            [$status, $body] = $this->tokenRequest($conn, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'Could not reach NetSuite to exchange the code: '.$e->getMessage()];
        }

        if ($status < 200 || $status >= 300 || ! isset($body['access_token'])) {
            $detail = (string) ($body['error_description'] ?? $body['error'] ?? $this->errorDetail($body));
            $conn->forceFill(['status' => 'error', 'last_error' => $detail])->save();

            return ['ok' => false, 'message' => 'NetSuite rejected the authorization: '.$detail];
        }

        $this->storeTokens($conn, $body);
        $conn->forceFill(['status' => 'active', 'last_error' => null])->save();

        return ['ok' => true, 'message' => 'NetSuite connected over OAuth 2.0.'];
    }

    /**
     * Return a valid access token, refreshing it first if it has expired (or is
     * within the refresh leeway). Throws if the refresh fails.
     */
    private function accessTokenFor(NetsuiteConnection $conn): string
    {
        $leeway = (int) config('services.netsuite.oauth_refresh_leeway', 120);
        $expired = $conn->token_expires_at === null
            || $conn->token_expires_at->subSeconds($leeway)->isPast();

        if (blank($conn->access_token) || $expired) {
            $this->refreshAccessToken($conn);
        }

        return (string) $conn->access_token;
    }

    /**
     * Use the refresh token to get a fresh access token.
     */
    private function refreshAccessToken(NetsuiteConnection $conn): void
    {
        if (blank($conn->refresh_token)) {
            throw new RuntimeException('No NetSuite refresh token — reconnect the OAuth 2.0 integration.');
        }

        [$status, $body] = $this->tokenRequest($conn, [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $conn->refresh_token,
        ]);

        if ($status < 200 || $status >= 300 || ! isset($body['access_token'])) {
            $detail = (string) ($body['error_description'] ?? $body['error'] ?? 'refresh failed');
            $conn->forceFill(['status' => 'error', 'last_error' => $detail])->save();

            throw new RuntimeException('NetSuite token refresh failed ('.$detail.'). Reconnect the OAuth 2.0 integration.');
        }

        $this->storeTokens($conn, $body);
    }

    /**
     * POST to the NetSuite OAuth 2.0 token endpoint with HTTP Basic client auth.
     *
     * @param  array<string, string>  $form
     * @return array{0: int, 1: array<string, mixed>} [status, decoded body]
     */
    private function tokenRequest(NetsuiteConnection $conn, array $form): array
    {
        $url = $this->baseUrl($conn).'/services/rest/auth/oauth2/v1/token';

        $response = Http::asForm()
            ->withBasicAuth((string) $conn->client_id, (string) $conn->client_secret)
            ->timeout((int) config('services.netsuite.timeout', 30))
            ->post($url, $form);

        $decoded = $response->json();

        return [$response->status(), is_array($decoded) ? $decoded : ['raw' => $response->body()]];
    }

    /**
     * Persist the tokens from a token-endpoint response.
     *
     * @param  array<string, mixed>  $body
     */
    private function storeTokens(NetsuiteConnection $conn, array $body): void
    {
        // NetSuite returns a rotated refresh token on some responses; keep the
        // existing one if none came back.
        $refresh = (isset($body['refresh_token']) && is_string($body['refresh_token']))
            ? $body['refresh_token']
            : $conn->refresh_token;

        $conn->forceFill([
            'access_token' => (string) $body['access_token'],
            'refresh_token' => $refresh,
            'token_expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 3600)),
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * Account host segment: lower-cased, underscores → dashes.
     */
    private function accountHost(string $accountId): string
    {
        return strtolower(str_replace('_', '-', $accountId));
    }

    /**
     * Build the OAuth 1.0a (TBA) Authorization header for a request.
     */
    private function authHeader(NetsuiteConnection $conn, string $method, string $url): string
    {
        $oauth = [
            'oauth_consumer_key' => $conn->consumer_key,
            'oauth_token' => $conn->token_id,
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => (string) time(),
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_version' => '1.0',
        ];

        $parts = parse_url($url);
        $baseUrl = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');
        parse_str($parts['query'] ?? '', $queryParams);

        // Signature base string: sorted (oauth + query) params, percent-encoded.
        $allParams = array_merge($queryParams, $oauth);
        ksort($allParams);

        $paramPairs = [];
        foreach ($allParams as $key => $value) {
            // OAuth signature params are always scalar (our query strings only
            // carry limit/offset); skip anything unexpected rather than cast it.
            if (! is_scalar($value)) {
                continue;
            }

            $paramPairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        $baseString = strtoupper($method).'&'.rawurlencode($baseUrl).'&'.rawurlencode(implode('&', $paramPairs));
        $signingKey = rawurlencode($conn->consumer_secret).'&'.rawurlencode($conn->token_secret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha256', $baseString, $signingKey, true));

        // The realm (account id) goes in the header but NOT the signature.
        $headerParts = ['realm="'.$this->realm($conn->account_id).'"'];
        foreach ($oauth as $key => $value) {
            $headerParts[] = $key.'="'.rawurlencode((string) $value).'"';
        }

        return 'OAuth '.implode(', ', $headerParts);
    }

    private function baseUrl(NetsuiteConnection $conn): string
    {
        $host = $this->accountHost($conn->account_id);
        $domain = (string) config('services.netsuite.rest_domain', 'suitetalk.api.netsuite.com');

        return "https://{$host}.{$domain}";
    }

    private function realm(string $accountId): string
    {
        return strtoupper($accountId);
    }

    /**
     * Pull a readable message out of a NetSuite error payload.
     *
     * @param  array<string, mixed>  $body
     */
    private function errorDetail(array $body): string
    {
        $details = $body['o:errorDetails'] ?? null;

        if (is_array($details) && isset($details[0]) && is_array($details[0])) {
            $code = (string) ($details[0]['o:errorCode'] ?? '');
            $detail = (string) ($details[0]['detail'] ?? '');

            return trim(($code !== '' ? $code.': ' : '').$detail) ?: 'Unknown error.';
        }

        if (isset($body['title']) && is_string($body['title'])) {
            return $body['title'];
        }

        if (isset($body['raw']) && is_string($body['raw']) && $body['raw'] !== '') {
            return Str::limit($body['raw'], 200);
        }

        return 'Unknown error.';
    }
}
