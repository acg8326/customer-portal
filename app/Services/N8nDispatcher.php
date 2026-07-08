<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIntegration;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Sends events to a user's connected n8n Webhook node.
 *
 * n8n integrations are outbound-only: the user pastes the Production URL of an
 * n8n Webhook node (+ an optional shared secret) and we POST a JSON payload to
 * it whenever something happens (e.g. a chat completes).
 */
class N8nDispatcher
{
    /**
     * POST an event to the user's n8n webhook, if they have one connected.
     * Never throws — a failed webhook must not break the user's action.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(User $user, string $event, array $payload): void
    {
        $integration = $user->integrations()
            ->where('provider', 'n8n')
            ->first();

        if (! $integration instanceof UserIntegration) {
            return;
        }

        try {
            $this->post($integration, $event, $payload);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Send a payload to the webhook and return the HTTP status code.
     * Throws on connection failure (used by the "Send test" button so the
     * user sees whether it worked).
     *
     * @param  array<string, mixed>  $payload
     */
    public function post(UserIntegration $integration, string $event, array $payload): int
    {
        $config = $integration->config ?? [];
        $url = (string) ($config['webhook_url'] ?? '');

        if ($url === '') {
            return 0;
        }

        // Re-check at send time: a stored URL could resolve to a private
        // address now (DNS rebinding), even if it was public when connected.
        if (! PublicUrl::isPublic($url)) {
            throw new RuntimeException('Refusing to send to a non-public webhook URL.');
        }

        $request = Http::timeout((int) config('integrations.n8n.timeout', 8))
            ->asJson();

        $secret = (string) ($config['secret'] ?? '');

        if ($secret !== '') {
            $header = (string) config('integrations.n8n.secret_header', 'X-AiMe-Secret');
            $request = $request->withHeaders([$header => $secret]);
        }

        $response = $request->post($url, [
            'event' => $event,
            'sent_at' => now()->toIso8601String(),
            'data' => $payload,
        ]);

        return $response->status();
    }
}
