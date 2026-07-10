<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Composio is a hosted tool gateway: it owns the OAuth apps for 250+ SaaS tools,
 * so users authorize *Composio* (not us) and we reach the tools through a single
 * Composio API key over MCP — no per-app client id/secret required.
 *
 * Connections are per user: each AiMe user maps to a Composio `user_id` (we use
 * the AiMe user id), and the MCP URL is scoped to that user id, so every person's
 * chat acts as their own connected account.
 */
class ComposioService
{
    public function enabled(): bool
    {
        return filled(config('services.composio.api_key'));
    }

    /**
     * Configured toolkits, keyed by slug. A toolkit is offered when it's usable:
     * a `managed` toolkit needs a pre-created auth-config id (Composio owns the
     * OAuth app), while a `credentials` toolkit (e.g. NetSuite) needs none — the
     * user pastes their own OAuth app's client id/secret at connect time and we
     * create the auth config on the fly.
     *
     * - `credentials`: secret fields collected to create the auth config (name => label).
     * - `initiation`: non-secret fields sent as connection_data (name => label).
     * - `optional_scopes`: extra OAuth scopes the user can opt into (scope => label).
     *
     * @return array<string, array{name: string, mode: string, auth_config_id: string, auth_scheme: string, scopes: string, credentials: array<string, string>, initiation: array<string, string>, optional_scopes: array<string, string>}>
     */
    public function toolkits(): array
    {
        $out = [];

        foreach ((array) config('services.composio.toolkits', []) as $key => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $mode = (string) ($meta['mode'] ?? 'managed');
            $authConfigId = (string) ($meta['auth_config_id'] ?? '');

            // Managed toolkits are only usable once their auth-config id is set;
            // credentials toolkits are always usable (creds come from the user).
            if ($mode !== 'credentials' && $authConfigId === '') {
                continue;
            }

            $out[(string) $key] = [
                'name' => (string) ($meta['name'] ?? ucfirst((string) $key)),
                'mode' => $mode,
                'auth_config_id' => $authConfigId,
                'auth_scheme' => (string) ($meta['auth_scheme'] ?? 'OAUTH2'),
                'scopes' => (string) ($meta['scopes'] ?? ''),
                'credentials' => $this->labelMap($meta['credentials'] ?? []),
                'initiation' => $this->labelMap($meta['initiation'] ?? []),
                'optional_scopes' => $this->labelMap($meta['optional_scopes'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @return array{name: string, mode: string, auth_config_id: string, auth_scheme: string, scopes: string, credentials: array<string, string>, initiation: array<string, string>, optional_scopes: array<string, string>}|null
     */
    public function toolkit(string $key): ?array
    {
        return $this->toolkits()[$key] ?? null;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, string>
     */
    private function labelMap($raw): array
    {
        $out = [];
        foreach ((array) $raw as $k => $v) {
            $out[(string) $k] = (string) $v;
        }

        return $out;
    }

    /**
     * Begin a per-user OAuth connection for a *managed* toolkit (Composio owns
     * the OAuth app) and return the provider consent URL. `$connectionData`
     * pre-fills any initiation fields.
     *
     * @param  array<string, string>  $connectionData
     */
    public function initiateLink(User $user, string $toolkit, string $callbackUrl, array $connectionData = []): string
    {
        $cfg = $this->toolkit($toolkit)
            ?? throw new RuntimeException("Composio toolkit [{$toolkit}] is not configured.");

        return $this->link($cfg['auth_config_id'], $user, $toolkit, $callbackUrl, $connectionData);
    }

    /**
     * Begin a per-user connection for a *credentials* toolkit (bring-your-own
     * OAuth app, e.g. NetSuite): create the auth config from the user's client
     * id/secret, then link with the initiation fields. Returns the consent URL.
     *
     * @param  array<string, string>  $connectionData  non-secret initiation fields (e.g. subdomain)
     * @param  list<string>  $extraScopes  optional scopes the user opted into
     */
    public function initiateWithCredentials(
        User $user,
        string $toolkit,
        string $callbackUrl,
        string $clientId,
        string $clientSecret,
        array $connectionData = [],
        array $extraScopes = [],
    ): string {
        $cfg = $this->toolkit($toolkit)
            ?? throw new RuntimeException("Composio toolkit [{$toolkit}] is not configured.");

        $scopes = array_values(array_filter(array_unique(array_merge(
            array_filter(array_map('trim', explode(',', $cfg['scopes']))),
            $extraScopes,
        ))));

        $authConfigId = $this->createCustomAuthConfig(
            $toolkit,
            $cfg['auth_scheme'],
            $clientId,
            $clientSecret,
            $scopes,
        );

        return $this->link($authConfigId, $user, $toolkit, $callbackUrl, $connectionData);
    }

    /**
     * Create a Composio auth config from the user's own OAuth app credentials
     * and return its id (ac_...).
     *
     * @param  list<string>  $scopes
     */
    private function createCustomAuthConfig(string $toolkit, string $authScheme, string $clientId, string $clientSecret, array $scopes): string
    {
        $credentials = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        if ($scopes !== []) {
            $credentials['scopes'] = implode(',', $scopes);
        }

        $resp = $this->http()->post($this->url('/api/v3/auth_configs'), [
            'toolkit' => ['slug' => $toolkit],
            'auth_config' => [
                'type' => 'use_custom_auth',
                'authScheme' => $authScheme,
                'name' => 'CWGP-AIMe '.ucfirst($toolkit),
                'credentials' => $credentials,
            ],
        ]);

        if (! $resp->successful()) {
            throw new RuntimeException('Composio auth-config creation failed (HTTP '.$resp->status().').');
        }

        /** @var array<string, mixed> $data */
        $data = (array) $resp->json();
        $ac = $data['auth_config'] ?? null;
        $id = is_array($ac) && is_string($ac['id'] ?? null) ? $ac['id'] : null;

        // Fall back to a top-level id in case the response is flattened.
        if (blank($id) && is_string($data['id'] ?? null)) {
            $id = $data['id'];
        }

        if (blank($id)) {
            throw new RuntimeException('Composio did not return an auth-config id.');
        }

        return $id;
    }

    /**
     * POST the link request for a resolved auth-config id, record the pending
     * connection, and return the consent URL to redirect the user to.
     *
     * @param  array<string, string>  $connectionData
     */
    private function link(string $authConfigId, User $user, string $toolkit, string $callbackUrl, array $connectionData): string
    {
        $payload = [
            'auth_config_id' => $authConfigId,
            'user_id' => (string) $user->id,
            'callback_url' => $callbackUrl,
        ];

        if ($connectionData !== []) {
            $payload['connection_data'] = $connectionData;
        }

        $resp = $this->http()->post($this->url('/api/v3/connected_accounts/link'), $payload);

        if (! $resp->successful()) {
            throw new RuntimeException('Composio link failed (HTTP '.$resp->status().').');
        }

        /** @var array<string, mixed> $data */
        $data = (array) $resp->json();
        $redirect = is_string($data['redirect_url'] ?? null) ? $data['redirect_url'] : null;

        if (blank($redirect)) {
            throw new RuntimeException('Composio did not return a redirect URL.');
        }

        $accountId = null;
        foreach (['connected_account_id', 'id', 'nano_id'] as $k) {
            if (is_string($data[$k] ?? null)) {
                $accountId = $data[$k];
                break;
            }
        }

        $user->composioConnections()->updateOrCreate(
            ['toolkit' => $toolkit],
            ['status' => 'initiated', 'connected_account_id' => $accountId],
        );

        return $redirect;
    }

    public function markActive(User $user, string $toolkit): void
    {
        $user->composioConnections()->updateOrCreate(
            ['toolkit' => $toolkit],
            ['status' => 'active'],
        );
    }

    public function disconnect(User $user, string $toolkit): void
    {
        // Best-effort: delete the account on Composio too, so a reconnect starts
        // clean instead of piling up stale duplicates (which cause the wrong
        // token to be used on execute).
        $accountId = $user->composioConnections()
            ->where('toolkit', $toolkit)
            ->value('connected_account_id');

        if (is_string($accountId) && $accountId !== '') {
            try {
                $this->http()->delete($this->url('/api/v3/connected_accounts/'.rawurlencode($accountId)));
            } catch (\Throwable) {
                // Ignore — local disconnect still proceeds.
            }
        }

        $user->composioConnections()->where('toolkit', $toolkit)->delete();
    }

    /**
     * The connected-account id we recorded for the toolkit a tool slug belongs
     * to (Composio slugs are prefixed with the uppercased toolkit slug), or null.
     */
    private function connectedAccountForSlug(User $user, string $slug): ?string
    {
        $upper = strtoupper($slug);

        foreach ($user->composioConnections()->where('status', 'active')->get() as $conn) {
            $prefix = strtoupper((string) $conn->toolkit).'_';
            $accountId = $conn->connected_account_id;

            if (is_string($accountId) && $accountId !== '' && str_starts_with($upper, $prefix)) {
                return $accountId;
            }
        }

        return null;
    }

    public function isConnected(User $user, string $toolkit): bool
    {
        return $user->composioConnections()
            ->where('toolkit', $toolkit)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Toolkit keys the user has a locally-active connection for (and that are
     * still configured). Used to decide whether a chat turn gets Composio tools.
     *
     * @return list<string>
     */
    public function activeToolkitKeys(User $user): array
    {
        $keys = [];

        foreach ($user->composioConnections()->where('status', 'active')->pluck('toolkit') as $toolkit) {
            $key = (string) $toolkit;

            if ($this->toolkit($key) !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Anthropic-format tool definitions for the given toolkit keys, capped so a
     * turn isn't flooded with hundreds of schemas. Composio tool slugs double as
     * the Anthropic tool name (they match the required name pattern); any that
     * don't (too long / bad chars) are skipped.
     *
     * @param  list<string>  $toolkitKeys
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function toolSchemas(array $toolkitKeys): array
    {
        if ($toolkitKeys === [] || ! $this->enabled()) {
            return [];
        }

        $limit = (int) config('services.composio.max_tools', 100);
        $out = [];

        foreach ($toolkitKeys as $key) {
            // Two passes, deduped, capped at $limit:
            //  1. important=true → each toolkit's *curated* high-value tools
            //     (e.g. Slack's send/search, which are late-alphabet and would
            //     otherwise be cut). Some toolkits curate this poorly, though.
            //  2. the general list → breadth (e.g. NetSuite's read tools, which
            //     its "important" set omits entirely).
            // `toolkit_versions=latest` is REQUIRED — without it some toolkits
            // (NetSuite) resolve to a version that returns zero tools.
            $bySlug = [];

            foreach (['true', null] as $important) {
                if (count($bySlug) >= $limit) {
                    break;
                }

                $query = [
                    'toolkit_slug' => $key,
                    'toolkit_versions' => (string) config('services.composio.tool_version', 'latest'),
                    'limit' => $limit,
                ];

                if ($important !== null) {
                    $query['important'] = $important;
                }

                $resp = $this->http()->get($this->url('/api/v3/tools'), $query);

                if (! $resp->successful()) {
                    continue;
                }

                foreach ((array) ($resp->json('items') ?? []) as $tool) {
                    if (count($bySlug) >= $limit) {
                        break;
                    }

                    if (! is_array($tool)) {
                        continue;
                    }

                    $slug = (string) ($tool['slug'] ?? '');

                    if ($slug === '' || isset($bySlug[$slug]) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $slug)) {
                        continue;
                    }

                    $schema = $tool['input_parameters'] ?? $tool['parameters'] ?? null;

                    $bySlug[$slug] = [
                        'name' => $slug,
                        'description' => (string) ($tool['description'] ?? ($tool['name'] ?? $slug)),
                        'input_schema' => is_array($schema) && $schema !== []
                            ? $schema
                            : ['type' => 'object', 'properties' => (object) []],
                    ];
                }
            }

            $out = array_merge($out, array_values($bySlug));
        }

        return $out;
    }

    /**
     * Execute a Composio tool for a user (server-side, with the API key).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, output: mixed}
     */
    public function execute(User $user, string $slug, array $arguments): array
    {
        $payload = [
            'user_id' => (string) $user->id,
            // Same version we advertised in toolSchemas — the implicit default
            // ('00000000_00') 404s for versioned toolkits like NetSuite.
            'version' => (string) config('services.composio.tool_version', 'latest'),
            'arguments' => (object) $arguments,
        ];

        // Pin to the exact connected account we recorded for this toolkit so a
        // stale/duplicate account (e.g. left over after a credential reset)
        // can't be picked when the user has more than one.
        $accountId = $this->connectedAccountForSlug($user, $slug);

        if ($accountId !== null) {
            $payload['connected_account_id'] = $accountId;
        }

        try {
            $resp = $this->http()->post($this->url('/api/v3/tools/execute/'.rawurlencode($slug)), $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => 'Could not reach the tool: '.$e->getMessage()];
        }

        $data = $resp->json();

        if (! $resp->successful()) {
            $msg = is_array($data) ? ($data['error']['message'] ?? $data['error'] ?? null) : null;

            return ['ok' => false, 'output' => is_string($msg) ? $msg : ('Tool call failed (HTTP '.$resp->status().').')];
        }

        // Composio wraps results as {successful, data, error}.
        if (is_array($data) && ($data['successful'] ?? true) === false) {
            $err = $data['error'] ?? 'Tool call failed.';

            return ['ok' => false, 'output' => is_string($err) ? $err : json_encode($err)];
        }

        return ['ok' => true, 'output' => is_array($data) ? ($data['data'] ?? $data) : $data];
    }

    /**
     * The live status of a user's connection from Composio (e.g. ACTIVE,
     * EXPIRED, INITIALIZING) or null if none / unreachable.
     */
    public function remoteStatus(User $user, string $toolkit): ?string
    {
        try {
            $resp = $this->http()->get($this->url('/api/v3/connected_accounts'), [
                'user_ids' => (string) $user->id,
                'toolkit_slugs' => $toolkit,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $items = (array) ($resp->json('items') ?? []);

        foreach ($items as $item) {
            if (is_array($item) && ($item['status'] ?? null) === 'ACTIVE') {
                return 'ACTIVE';
            }
        }

        $first = $items[0] ?? null;

        return is_array($first) && isset($first['status']) ? (string) $first['status'] : null;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)
            ->withHeaders(['x-api-key' => (string) config('services.composio.api_key')])
            ->acceptJson()
            ->asJson();
    }

    private function base(): string
    {
        return rtrim((string) config('services.composio.base_url', 'https://backend.composio.dev'), '/');
    }

    private function url(string $path): string
    {
        return $this->base().$path;
    }
}
