<?php

namespace App\Http\Controllers;

use App\Models\McpServer;
use App\Rules\PublicHttpUrl;
use App\Services\Mcp\McpOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class McpServerController extends Controller
{
    public function __construct(private McpOAuthService $oauth) {}

    /**
     * Connect a new MCP server for the current user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'url', 'max:2048', new PublicHttpUrl],
            'auth_type' => ['sometimes', 'in:token,oauth'],
            'auth_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $authType = $validated['auth_type'] ?? 'token';

        // Verify the URL actually behaves like an MCP endpoint before saving,
        // so a wrong URL (e.g. the tool's web page) is caught here, not mid-chat.
        $check = $this->oauth->validateEndpoint(
            $validated['url'],
            $authType === 'token' ? ($validated['auth_token'] ?? null) : null,
        );

        if (! $check['ok']) {
            return back()->withErrors(['url' => $check['message']])->withInput();
        }

        $server = $request->user()->mcpServers()->create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'auth_type' => $authType,
            'auth_token' => $authType === 'token' ? ($validated['auth_token'] ?? null) : null,
            'enabled' => true,
        ]);

        // OAuth servers still need the user to authorize — the UI shows a
        // "Connect" button that hits oauthConnect() as a full-page navigation.
        if ($server->usesOAuth()) {
            return back()->with('success', "Added \"{$server->name}\" — click Connect to authorize.");
        }

        return back()->with('success', "MCP server \"{$server->name}\" connected.");
    }

    /**
     * Update an MCP server (rename, change URL/token, enable/disable).
     */
    public function update(Request $request, McpServer $mcpServer): RedirectResponse
    {
        $this->ensureOwner($request, $mcpServer);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'url' => ['sometimes', 'required', 'url', 'max:2048', new PublicHttpUrl],
            'auth_token' => ['nullable', 'string', 'max:2048'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        // Only overwrite the token when a new one is actually provided.
        if (($validated['auth_token'] ?? null) === null) {
            unset($validated['auth_token']);
        }

        $mcpServer->fill($validated)->save();

        return back()->with('success', 'MCP server updated.');
    }

    /**
     * Disconnect an MCP server.
     */
    public function destroy(Request $request, McpServer $mcpServer): RedirectResponse
    {
        $this->ensureOwner($request, $mcpServer);

        $mcpServer->delete();

        return back()->with('success', 'MCP server disconnected.');
    }

    /**
     * One-click connect from the catalog: find-or-create the user's MCP server
     * for a catalog entry, then start its OAuth flow.
     */
    public function catalogConnect(Request $request, string $key): RedirectResponse
    {
        $entry = collect((array) config('integrations.mcp_catalog', []))
            ->first(fn ($e): bool => is_array($e) && ($e['key'] ?? null) === $key);

        if (! is_array($entry)) {
            abort(404);
        }

        // Catch a wrong/stale catalog URL before starting a doomed OAuth flow.
        $check = $this->oauth->validateEndpoint((string) $entry['url']);

        if (! $check['ok']) {
            return redirect()->route('integrations')
                ->with('error', "Can't connect {$entry['name']}: {$check['message']}");
        }

        $server = $request->user()->mcpServers()->firstOrCreate(
            ['catalog_key' => $key],
            [
                'name' => (string) $entry['name'],
                'url' => (string) $entry['url'],
                'auth_type' => 'oauth',
                'enabled' => true,
            ],
        );

        return $this->oauthConnect($request, $server);
    }

    /**
     * Start the OAuth flow: discover + register, then redirect the user to the
     * server's own authorization page.
     */
    public function oauthConnect(Request $request, McpServer $mcpServer): RedirectResponse
    {
        $this->ensureOwner($request, $mcpServer);

        try {
            $flow = $this->oauth->beginAuthorization($mcpServer, route('integrations.mcp.oauth.callback'));
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('integrations')
                ->with('error', 'Could not start the connection: '.$e->getMessage());
        }

        // Stash PKCE verifier + CSRF state for the callback (server id too, since
        // the redirect URI is fixed and carries no server reference).
        $request->session()->put('mcp_oauth', [
            'server_id' => $mcpServer->id,
            'state' => $flow['state'],
            'verifier' => $flow['verifier'],
        ]);

        return redirect()->away($flow['url']);
    }

    /**
     * OAuth redirect target: validate state, exchange the code, store tokens.
     */
    public function oauthCallback(Request $request): RedirectResponse
    {
        /** @var array{server_id?: int, state?: string, verifier?: string} $flow */
        $flow = (array) $request->session()->pull('mcp_oauth', []);

        if ($request->filled('error')) {
            return redirect()->route('integrations')
                ->with('error', 'Authorization was denied by the provider.');
        }

        $code = $request->string('code')->toString();
        $state = $request->string('state')->toString();
        $expectedState = $flow['state'] ?? '';

        if ($code === '' || $expectedState === '' || ! hash_equals($expectedState, $state)) {
            return redirect()->route('integrations')
                ->with('error', 'Invalid or expired authorization response. Please try connecting again.');
        }

        $server = McpServer::query()
            ->whereKey($flow['server_id'] ?? 0)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $server instanceof McpServer) {
            return redirect()->route('integrations')->with('error', 'Could not find the server to connect.');
        }

        try {
            $this->oauth->completeAuthorization($server, $code, $flow['verifier'] ?? '', route('integrations.mcp.oauth.callback'));
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('integrations')
                ->with('error', 'Could not complete the connection: '.$e->getMessage());
        }

        return redirect()->route('integrations')->with('success', "Connected \"{$server->name}\".");
    }

    private function ensureOwner(Request $request, McpServer $mcpServer): void
    {
        abort_unless($mcpServer->user_id === $request->user()->id, 404);
    }
}
