<?php

namespace App\Http\Middleware;

use App\Models\GatewayToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates LLM-gateway requests by a personal access token carried as a
 * Bearer token (Claude Code's ANTHROPIC_AUTH_TOKEN). Resolves the owning user
 * onto the request so downstream policy (assigned model, token budget) applies.
 * Errors mirror the Anthropic error shape so the client shows a sensible message.
 */
class GatewayAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Feature off → the gateway doesn't exist.
        if (! config('services.anthropic.gateway.enabled', false)) {
            abort(404);
        }

        $plaintext = $request->bearerToken();

        if (blank($plaintext)) {
            return $this->error('authentication_error', 'Missing bearer token. Set ANTHROPIC_AUTH_TOKEN to your AiMe developer token.', 401);
        }

        $token = GatewayToken::findActive($plaintext);

        if ($token === null || $token->user === null) {
            return $this->error('authentication_error', 'Invalid or revoked token.', 401);
        }

        // Touch last_used_at at most once a minute to avoid a write per request.
        if ($token->last_used_at === null || $token->last_used_at->lt(Carbon::now()->subMinute())) {
            $token->forceFill(['last_used_at' => Carbon::now()])->save();
        }

        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }

    private function error(string $type, string $message, int $status): JsonResponse
    {
        return response()->json([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ], $status);
    }
}
