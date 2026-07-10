<?php

namespace App\Http\Controllers;

use App\Models\McpServer;
use App\Models\UserIntegration;
use App\Rules\PublicHttpUrl;
use App\Services\ComposioService;
use App\Services\N8nDispatcher;
use App\Services\NetsuiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    /**
     * Render the Integrations page with the user's current connections.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Integrations', [
            'live' => array_values(config('integrations.live', [])),
            'webhookProviders' => array_values(config('integrations.webhook_providers', [])),
            'connections' => $this->connections($request),
            'mcpServers' => $this->mcpServers($request),
            'composio' => $this->composio($request),
            'netsuite' => $this->netsuite($request),
        ]);
    }

    /**
     * Native NetSuite (TBA) connection state for the current user. Secrets are
     * never returned — only the account id and status.
     *
     * @return array{enabled: bool, connected: bool, accountId: string|null, authType: string|null, status: string|null, lastError: string|null}
     */
    private function netsuite(Request $request): array
    {
        $service = app(NetsuiteService::class);
        $conn = $service->connectionFor($request->user());

        return [
            'enabled' => $service->enabled(),
            // A 'pending' OAuth2 row (awaiting consent) isn't a live connection.
            'connected' => $conn !== null && $conn->status !== 'pending',
            'accountId' => $conn?->account_id,
            'authType' => $conn?->auth_type,
            'status' => $conn?->status,
            'lastError' => $conn?->last_error,
        ];
    }

    /**
     * Composio-brokered per-user tool connections (enabled flag + each
     * configured toolkit annotated with the current user's connection state).
     * `fields` lists any values the user must enter before consent (e.g.
     * NetSuite's account id), so the UI can prompt for them.
     *
     * @return array{enabled: bool, toolkits: array<int, array{key: string, name: string, connected: bool, mode: string, credentialFields: array<int, array{name: string, label: string}>, fields: array<int, array{name: string, label: string}>, optionalScopes: array<int, array{name: string, label: string}>}>}
     */
    private function composio(Request $request): array
    {
        $composio = app(ComposioService::class);

        $pairs = static function (array $map): array {
            $out = [];
            foreach ($map as $name => $label) {
                $out[] = ['name' => $name, 'label' => $label];
            }

            return $out;
        };

        $toolkits = [];
        foreach ($composio->toolkits() as $key => $meta) {
            $toolkits[] = [
                'key' => $key,
                'name' => $meta['name'],
                'connected' => $composio->isConnected($request->user(), $key),
                'mode' => $meta['mode'],
                // Secret fields (create the auth config) + non-secret initiation
                // fields + optional scope toggles, so the UI can prompt for them.
                'credentialFields' => $pairs($meta['credentials']),
                'fields' => $pairs($meta['initiation']),
                'optionalScopes' => $pairs($meta['optional_scopes']),
            ];
        }

        return [
            'enabled' => $composio->enabled(),
            'toolkits' => $toolkits,
        ];
    }

    /**
     * Connect or update a user's outbound webhook for a provider (n8n, zapier,
     * or a generic webhook endpoint — they all work the same way).
     */
    public function connectWebhook(Request $request, string $provider): RedirectResponse
    {
        $this->assertWebhookProvider($provider);

        $validated = $request->validate([
            'webhook_url' => ['required', 'url', 'max:2048', new PublicHttpUrl],
            'secret' => ['nullable', 'string', 'max:255'],
        ]);

        $request->user()->integrations()->updateOrCreate(
            ['provider' => $provider],
            [
                'config' => [
                    'webhook_url' => $validated['webhook_url'],
                    'secret' => $validated['secret'] ?? '',
                ],
                'connected_at' => now(),
            ],
        );

        return back()->with('success', ucfirst($provider).' connected.');
    }

    /**
     * Send a test event to a connected webhook provider.
     */
    public function testWebhook(Request $request, string $provider, N8nDispatcher $dispatcher): RedirectResponse
    {
        $this->assertWebhookProvider($provider);

        $integration = $request->user()->integrations()
            ->where('provider', $provider)
            ->first();

        if (! $integration instanceof UserIntegration) {
            return back()->with('error', 'Connect '.ucfirst($provider).' first, then send a test.');
        }

        try {
            $status = $dispatcher->post($integration, 'test.ping', [
                'message' => 'Test event from AiMe BOT.',
                'user' => $request->user()->only(['id', 'name', 'email']),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not reach your '.ucfirst($provider).' webhook. Check the URL and that it is active.');
        }

        if ($status >= 200 && $status < 300) {
            return back()->with('success', "Test event delivered (HTTP {$status}). Check your {$provider} execution log.");
        }

        return back()->with('error', ucfirst($provider)." responded with HTTP {$status}. Make sure the webhook is active and the URL is the Production URL.");
    }

    private function assertWebhookProvider(string $provider): void
    {
        abort_unless(
            in_array($provider, (array) config('integrations.webhook_providers', []), true),
            404,
        );
    }

    /**
     * Disconnect a provider.
     */
    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $request->user()->integrations()
            ->where('provider', $provider)
            ->delete();

        return back()->with('success', ucfirst($provider).' disconnected.');
    }

    /**
     * A map of provider => connection summary for the current user.
     *
     * @return array<string, array{connected: bool, endpoint: string|null, updated_at: string|null}>
     */
    private function connections(Request $request): array
    {
        $out = [];

        foreach ($request->user()->integrations()->get() as $integration) {
            $config = $integration->config ?? [];

            $out[$integration->provider] = [
                'connected' => true,
                'endpoint' => $this->maskUrl((string) ($config['webhook_url'] ?? '')),
                'updated_at' => $integration->connected_at?->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * The current user's MCP servers (secrets never leave the server).
     *
     * @return array<int, array{id: int, name: string, url: string, enabled: bool, auth_type: string, has_token: bool, oauth_connected: bool}>
     */
    private function mcpServers(Request $request): array
    {
        return $request->user()->mcpServers()
            ->whereNull('catalog_key') // catalog apps are shown as their own cards
            ->orderBy('name')
            ->get()
            ->map(fn (McpServer $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'url' => $s->url,
                'enabled' => $s->enabled,
                'auth_type' => $s->auth_type,
                'has_token' => filled($s->auth_token),
                'oauth_connected' => $s->oauthConnected(),
            ])
            ->all();
    }

    /**
     * Show only the host of a stored URL so a secret path isn't echoed back.
     */
    private function maskUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
