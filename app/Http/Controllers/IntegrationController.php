<?php

namespace App\Http\Controllers;

use App\Models\McpServer;
use App\Models\UserIntegration;
use App\Rules\PublicHttpUrl;
use App\Services\N8nDispatcher;
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
            'connections' => $this->connections($request),
            'mcpServers' => $this->mcpServers($request),
        ]);
    }

    /**
     * Connect or update the user's n8n webhook.
     */
    public function connectN8n(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'webhook_url' => ['required', 'url', 'max:2048', new PublicHttpUrl],
            'secret' => ['nullable', 'string', 'max:255'],
        ]);

        $request->user()->integrations()->updateOrCreate(
            ['provider' => 'n8n'],
            [
                'config' => [
                    'webhook_url' => $validated['webhook_url'],
                    'secret' => $validated['secret'] ?? '',
                ],
                'connected_at' => now(),
            ],
        );

        return back()->with('success', 'n8n connected.');
    }

    /**
     * Send a test event to the connected n8n webhook.
     */
    public function testN8n(Request $request, N8nDispatcher $dispatcher): RedirectResponse
    {
        $integration = $request->user()->integrations()
            ->where('provider', 'n8n')
            ->first();

        if (! $integration instanceof UserIntegration) {
            return back()->with('error', 'Connect n8n first, then send a test.');
        }

        try {
            $status = $dispatcher->post($integration, 'test.ping', [
                'message' => 'Test event from AiMe BOT.',
                'user' => $request->user()->only(['id', 'name', 'email']),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not reach your n8n webhook. Check the URL and that the workflow is active.');
        }

        if ($status >= 200 && $status < 300) {
            return back()->with('success', "Test event delivered (HTTP {$status}). Check your n8n execution log.");
        }

        return back()->with('error', "n8n responded with HTTP {$status}. Make sure the Webhook node is active and the URL is the Production URL.");
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
     * The current user's MCP servers (token never leaves the server).
     *
     * @return array<int, array{id: int, name: string, url: string, enabled: bool, has_token: bool}>
     */
    private function mcpServers(Request $request): array
    {
        return $request->user()->mcpServers()
            ->orderBy('name')
            ->get()
            ->map(fn (McpServer $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'url' => $s->url,
                'enabled' => $s->enabled,
                'has_token' => filled($s->auth_token),
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
