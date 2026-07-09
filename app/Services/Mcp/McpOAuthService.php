<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Drives the OAuth 2.1 authorization-code (+ PKCE) flow for remote MCP servers,
 * following the MCP authorization spec:
 *
 *   1. Protected Resource Metadata (RFC 9728) — find the authorization server.
 *   2. Authorization Server Metadata (RFC 8414 / OpenID Connect discovery) —
 *      find the authorize / token / registration endpoints.
 *   3. Dynamic Client Registration (RFC 7591) — self-register a client, so most
 *      servers need no manually-created app.
 *   4. Authorization request with PKCE (S256) + state.
 *   5. Token exchange, and later refresh.
 *
 * Every URL touched — the server, discovered endpoints, and the authorization
 * server itself — is SSRF-guarded via {@see PublicUrl} before any request.
 */
class McpOAuthService
{
    private int $timeout;

    private int $leeway;

    public function __construct()
    {
        $this->timeout = (int) config('integrations.mcp_oauth.timeout', 10);
        $this->leeway = (int) config('integrations.mcp_oauth.refresh_leeway', 120);
    }

    /**
     * Discover + register (if needed), then build the authorization redirect.
     *
     * Persists the discovered metadata and client identity on the server, and
     * returns the URL to send the user to plus the CSRF `state` and PKCE
     * `verifier` the caller must stash (in the session) for the callback.
     *
     * @return array{url: string, state: string, verifier: string}
     */
    public function beginAuthorization(McpServer $server, string $redirectUri): array
    {
        $meta = $this->discover($server);

        if (blank($server->oauth_client_id)) {
            $reg = $this->register($meta['registration_endpoint'], $redirectUri, $meta);
            $server->oauth_client_id = $reg['client_id'];
            $server->oauth_client_secret = $reg['client_secret'];
        }

        $server->oauth_metadata = $meta;
        $server->save();

        $verifier = Str::random(96);
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $state = Str::random(40);

        $params = [
            'response_type' => 'code',
            'client_id' => $server->oauth_client_id,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        if (($scopes = $this->scopes($meta)) !== '') {
            $params['scope'] = $scopes;
        }

        if (filled($meta['resource'])) {
            $params['resource'] = $meta['resource'];
        }

        $endpoint = $meta['authorization_endpoint'];
        $this->assertPublic($endpoint);
        $url = $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query($params);

        return ['url' => $url, 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * Exchange the authorization code for tokens and persist them.
     */
    public function completeAuthorization(McpServer $server, string $code, string $verifier, string $redirectUri): void
    {
        $meta = $server->oauthMetadata();
        $endpoint = is_string($meta['token_endpoint'] ?? null) ? $meta['token_endpoint'] : null;

        if (blank($endpoint)) {
            throw new RuntimeException('Missing token endpoint; reconnect this server.');
        }

        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => (string) $server->oauth_client_id,
            'code_verifier' => $verifier,
        ];

        if (is_string($meta['resource'] ?? null) && filled($meta['resource'])) {
            $payload['resource'] = $meta['resource'];
        }

        $this->storeTokenResponse($server, $this->tokenRequest($endpoint, $payload, $server));
    }

    /**
     * Sanity-check that a URL actually behaves like an MCP endpoint before we
     * save/connect it — this catches the classic mistake of pasting a tool's
     * web-page URL instead of its MCP server URL.
     *
     * Conservative by design: it only rejects clearly-wrong responses (an HTML
     * page, a 404, or an unreachable host). Anything ambiguous — a 401/403 auth
     * challenge, a JSON-RPC reply, an SSE stream, a redirect — is accepted, so a
     * legitimate server is never blocked.
     *
     * @return array{ok: bool, message: string}
     */
    public function validateEndpoint(string $url, ?string $token = null): array
    {
        if (! PublicUrl::isPublic($url)) {
            return ['ok' => false, 'message' => 'That URL is not a public https address.'];
        }

        try {
            $req = Http::timeout($this->timeout)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Accept' => 'application/json, text/event-stream']);

            if (filled($token)) {
                $req = $req->withToken($token);
            }

            // A minimal MCP "initialize" handshake over the Streamable HTTP transport.
            $resp = $req->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => [
                        'name' => (string) config('integrations.mcp_oauth.client_name', 'CWGP-AIMe'),
                        'version' => '1.0',
                    ],
                ],
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => "Couldn't reach that URL. Check it's correct and publicly reachable."];
        }

        $status = $resp->status();

        // Auth required → it IS an MCP server; the OAuth/token flow handles it.
        if ($status === 401 || $status === 403) {
            return ['ok' => true, 'message' => 'MCP endpoint (authentication required).'];
        }

        if ($status === 404) {
            return ['ok' => false, 'message' => 'No MCP server at that URL (404) — check the path (usually not the app\'s web page).'];
        }

        // An HTML page is the classic wrong-URL mistake (e.g. the app dashboard).
        $contentType = strtolower($resp->header('Content-Type'));
        $body = ltrim($resp->body());

        if (str_contains($contentType, 'text/html')
            || str_starts_with(strtolower($body), '<!doctype')
            || str_starts_with(strtolower($body), '<html')) {
            return ['ok' => false, 'message' => 'That looks like a web page, not an MCP server URL — use the tool\'s MCP endpoint (often ends in /mcp).'];
        }

        return ['ok' => true, 'message' => 'Looks like an MCP endpoint.'];
    }

    /**
     * A valid access token for chat use, refreshing first if it's expiring.
     * Null if the server isn't OAuth-connected or the token can't be renewed.
     */
    public function accessToken(McpServer $server): ?string
    {
        if (! $server->usesOAuth()) {
            return null;
        }

        if ($server->tokenExpired($this->leeway) && ! $this->refresh($server)) {
            return null;
        }

        return $server->oauth_access_token;
    }

    /**
     * Refresh the access token using the stored refresh token. Returns whether
     * a new token was obtained.
     */
    public function refresh(McpServer $server): bool
    {
        if (blank($server->oauth_refresh_token)) {
            return false;
        }

        $meta = $server->oauthMetadata();
        $endpoint = is_string($meta['token_endpoint'] ?? null) ? $meta['token_endpoint'] : null;

        if (blank($endpoint)) {
            return false;
        }

        try {
            $data = $this->tokenRequest($endpoint, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $server->oauth_refresh_token,
                'client_id' => (string) $server->oauth_client_id,
            ], $server);

            $this->storeTokenResponse($server, $data);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Discover the authorization server and its endpoints for a given server.
     *
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, registration_endpoint: string|null, scopes_supported: list<string>, resource: string|null}
     */
    private function discover(McpServer $server): array
    {
        $this->assertPublic($server->url);

        $prm = $this->fetchProtectedResourceMetadata($server->url);

        $asUrl = null;
        foreach ((array) ($prm['authorization_servers'] ?? []) as $candidate) {
            if (is_string($candidate) && PublicUrl::isPublic($candidate)) {
                $asUrl = $candidate;
                break;
            }
        }
        $asUrl ??= $this->origin($server->url);

        $as = $this->fetchAuthServerMetadata($asUrl);

        return [
            'issuer' => is_string($as['issuer'] ?? null) ? $as['issuer'] : $asUrl,
            'authorization_endpoint' => (string) $as['authorization_endpoint'],
            'token_endpoint' => (string) $as['token_endpoint'],
            'registration_endpoint' => is_string($as['registration_endpoint'] ?? null) ? $as['registration_endpoint'] : null,
            'scopes_supported' => array_values(array_filter((array) ($as['scopes_supported'] ?? []), 'is_string')),
            'resource' => is_string($prm['resource'] ?? null) ? $prm['resource'] : null,
        ];
    }

    /**
     * Self-register an OAuth client via Dynamic Client Registration.
     *
     * @param  array{scopes_supported: list<string>}  $meta
     * @return array{client_id: string, client_secret: string|null}
     */
    private function register(?string $endpoint, string $redirectUri, array $meta): array
    {
        if (blank($endpoint)) {
            throw new RuntimeException("This server doesn't support automatic app registration. A client ID must be configured manually.");
        }

        $this->assertPublic($endpoint);

        $body = [
            'client_name' => (string) config('integrations.mcp_oauth.client_name', 'CWGP-AIMe'),
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];

        if (($scopes = $this->scopes($meta)) !== '') {
            $body['scope'] = $scopes;
        }

        $resp = Http::timeout($this->timeout)->acceptJson()->asJson()->post($endpoint, $body);

        if (! $resp->successful()) {
            throw new RuntimeException('Client registration failed (HTTP '.$resp->status().').');
        }

        $data = $resp->json();

        if (! is_array($data) || blank($data['client_id'] ?? null)) {
            throw new RuntimeException('Client registration returned no client_id.');
        }

        return [
            'client_id' => (string) $data['client_id'],
            'client_secret' => is_string($data['client_secret'] ?? null) ? $data['client_secret'] : null,
        ];
    }

    /**
     * POST to a token endpoint and return the decoded response.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function tokenRequest(string $endpoint, array $payload, McpServer $server): array
    {
        $this->assertPublic($endpoint);

        $req = Http::timeout($this->timeout)->acceptJson()->asForm();

        // Confidential client (a secret was issued) authenticates via HTTP Basic.
        if (filled($server->oauth_client_secret)) {
            $req = $req->withBasicAuth((string) $server->oauth_client_id, (string) $server->oauth_client_secret);
        }

        $resp = $req->post($endpoint, $payload);

        if (! $resp->successful()) {
            throw new RuntimeException('Token request failed (HTTP '.$resp->status().').');
        }

        $data = $resp->json();

        if (! is_array($data) || blank($data['access_token'] ?? null)) {
            throw new RuntimeException('Token response missing access_token.');
        }

        return $data;
    }

    /**
     * Persist access/refresh tokens and expiry from a token response.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeTokenResponse(McpServer $server, array $data): void
    {
        $server->oauth_access_token = (string) $data['access_token'];

        if (is_string($data['refresh_token'] ?? null) && filled($data['refresh_token'])) {
            $server->oauth_refresh_token = $data['refresh_token'];
        }

        $server->oauth_expires_at = isset($data['expires_in']) && is_numeric($data['expires_in'])
            ? now()->addSeconds((int) $data['expires_in'])
            : null;

        $server->save();
    }

    /**
     * Fetch RFC 9728 protected-resource metadata, trying path-aware then origin.
     *
     * @return array<string, mixed>
     */
    private function fetchProtectedResourceMetadata(string $serverUrl): array
    {
        $origin = $this->origin($serverUrl);
        $path = rtrim((string) (parse_url($serverUrl, PHP_URL_PATH) ?: ''), '/');

        $candidates = array_unique(array_filter([
            $path !== '' ? $origin.'/.well-known/oauth-protected-resource'.$path : null,
            $origin.'/.well-known/oauth-protected-resource',
        ]));

        foreach ($candidates as $url) {
            $data = $this->getJson($url);
            if (is_array($data) && ! empty($data['authorization_servers'])) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Fetch RFC 8414 / OIDC authorization-server metadata for an issuer URL.
     *
     * @return array<string, mixed>
     */
    private function fetchAuthServerMetadata(string $asUrl): array
    {
        $origin = $this->origin($asUrl);
        $path = rtrim((string) (parse_url($asUrl, PHP_URL_PATH) ?: ''), '/');

        $candidates = array_unique(array_filter([
            $path !== '' ? $origin.'/.well-known/oauth-authorization-server'.$path : null,
            $origin.'/.well-known/oauth-authorization-server',
            $path !== '' ? $origin.'/.well-known/openid-configuration'.$path : null,
            rtrim($asUrl, '/').'/.well-known/openid-configuration',
            $origin.'/.well-known/openid-configuration',
        ]));

        foreach ($candidates as $url) {
            $data = $this->getJson($url);
            if (is_array($data)
                && filled($data['authorization_endpoint'] ?? null)
                && filled($data['token_endpoint'] ?? null)) {
                return $data;
            }
        }

        throw new RuntimeException('Could not discover the OAuth endpoints for this server.');
    }

    /**
     * GET a public URL and return decoded JSON, or null on any failure.
     *
     * @return array<mixed>|null
     */
    private function getJson(string $url): ?array
    {
        if (! PublicUrl::isPublic($url)) {
            return null;
        }

        try {
            $resp = Http::timeout($this->timeout)->acceptJson()->get($url);

            if (! $resp->successful()) {
                return null;
            }

            $json = $resp->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Requested scopes: the configured override, else the server's advertised set.
     *
     * @param  array{scopes_supported?: list<string>}  $meta
     */
    private function scopes(array $meta): string
    {
        $configured = trim((string) config('integrations.mcp_oauth.scopes', ''));

        if ($configured !== '') {
            return $configured;
        }

        return implode(' ', $meta['scopes_supported'] ?? []);
    }

    private function assertPublic(string $url): void
    {
        if (! PublicUrl::isPublic($url)) {
            throw new RuntimeException('Refusing to contact a non-public URL.');
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
