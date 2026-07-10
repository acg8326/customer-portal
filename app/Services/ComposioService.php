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
     * Toolkits that are configured (have an auth-config id). Others are ignored
     * so the UI never offers a broken button.
     *
     * @return array<string, array{name: string, auth_config_id: string}>
     */
    public function toolkits(): array
    {
        $out = [];

        foreach ((array) config('services.composio.toolkits', []) as $key => $meta) {
            if (! is_array($meta) || blank($meta['auth_config_id'] ?? null)) {
                continue;
            }

            $out[(string) $key] = [
                'name' => (string) ($meta['name'] ?? ucfirst((string) $key)),
                'auth_config_id' => (string) $meta['auth_config_id'],
            ];
        }

        return $out;
    }

    /**
     * @return array{name: string, auth_config_id: string}|null
     */
    public function toolkit(string $key): ?array
    {
        return $this->toolkits()[$key] ?? null;
    }

    /**
     * Begin a per-user OAuth connection via Composio-managed auth and return the
     * provider consent URL to redirect the user to.
     */
    public function initiateLink(User $user, string $toolkit, string $callbackUrl): string
    {
        $cfg = $this->toolkit($toolkit)
            ?? throw new RuntimeException("Composio toolkit [{$toolkit}] is not configured.");

        $resp = $this->http()->post($this->url('/api/v3/connected_accounts/link'), [
            'auth_config_id' => $cfg['auth_config_id'],
            'user_id' => (string) $user->id,
            'callback_url' => $callbackUrl,
        ]);

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
        $user->composioConnections()->where('toolkit', $toolkit)->delete();
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

        $limit = (int) config('services.composio.max_tools', 40);
        $out = [];

        foreach ($toolkitKeys as $key) {
            // `important=true` returns each toolkit's curated high-value tools
            // (search, list, send, fetch…) instead of the first N alphabetically,
            // so the model actually gets the tools people ask for.
            $resp = $this->http()->get($this->url('/api/v3/tools'), [
                'toolkit_slug' => $key,
                'important' => 'true',
                'limit' => $limit,
            ]);

            if (! $resp->successful()) {
                continue;
            }

            foreach ((array) ($resp->json('items') ?? []) as $tool) {
                if (! is_array($tool)) {
                    continue;
                }

                $slug = (string) ($tool['slug'] ?? '');

                if ($slug === '' || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $slug)) {
                    continue;
                }

                $schema = $tool['input_parameters'] ?? $tool['parameters'] ?? null;

                $out[] = [
                    'name' => $slug,
                    'description' => (string) ($tool['description'] ?? ($tool['name'] ?? $slug)),
                    'input_schema' => is_array($schema) && $schema !== []
                        ? $schema
                        : ['type' => 'object', 'properties' => (object) []],
                ];
            }
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
        try {
            $resp = $this->http()->post($this->url('/api/v3/tools/execute/'.rawurlencode($slug)), [
                'user_id' => (string) $user->id,
                'arguments' => (object) $arguments,
            ]);
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
