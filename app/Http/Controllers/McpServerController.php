<?php

namespace App\Http\Controllers;

use App\Models\McpServer;
use App\Rules\PublicHttpUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class McpServerController extends Controller
{
    /**
     * Connect a new MCP server for the current user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'url', 'max:2048', new PublicHttpUrl],
            'auth_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $request->user()->mcpServers()->create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'auth_token' => $validated['auth_token'] ?? null,
            'enabled' => true,
        ]);

        return back()->with('success', "MCP server \"{$validated['name']}\" connected.");
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

    private function ensureOwner(Request $request, McpServer $mcpServer): void
    {
        abort_unless($mcpServer->user_id === $request->user()->id, 404);
    }
}
