<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GatewayToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Developer access — a user manages their own LLM-gateway tokens for using
 * Claude Code (or any Anthropic client) through AiMe. Only available when the
 * gateway is enabled (config services.anthropic.gateway.enabled).
 */
class GatewayTokenController extends Controller
{
    private function ensureEnabled(): void
    {
        abort_unless((bool) config('services.anthropic.gateway.enabled', false), 404);
    }

    public function index(Request $request): Response
    {
        $this->ensureEnabled();

        $user = $request->user();

        return Inertia::render('settings/DeveloperAccess', [
            // What the developer pastes into Claude Code.
            'baseUrl' => rtrim((string) config('app.url'), '/').'/llm',
            // Their governance, shown so they know what they'll get.
            'assignedModel' => $user->assigned_model,
            // Only live tokens — revoked rows are kept for audit but hidden,
            // so revoking a token makes it disappear from the list.
            'tokens' => $user->gatewayTokens()
                ->whereNull('revoked_at')
                ->orderByDesc('id')
                ->get(['id', 'name', 'last_four', 'last_used_at', 'created_at'])
                ->map(fn (GatewayToken $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'last_four' => $t->last_four,
                    'last_used_at' => $t->last_used_at?->diffForHumans(),
                    'created_at' => $t->created_at?->toFormattedDateString(),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        [, $plaintext] = GatewayToken::issue($request->user(), $validated['name']);

        // Shown once — the plaintext is never recoverable after this.
        return back()->with('gatewayToken', $plaintext);
    }

    public function destroy(Request $request, GatewayToken $gatewayToken): RedirectResponse
    {
        $this->ensureEnabled();

        abort_unless($gatewayToken->user_id === $request->user()->id, 403);

        // Soft-revoke: keep the row (audit) but reject the token from now on.
        $gatewayToken->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', 'Token revoked.');
    }
}
