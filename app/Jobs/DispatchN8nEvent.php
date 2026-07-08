<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserIntegration;
use App\Services\N8nDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Delivers a chat/project event to a user's connected n8n webhook off the
 * request path, so a slow or failing webhook never delays the user's reply.
 * Retries on transient failures (connection error or n8n 5xx).
 */
class DispatchN8nEvent implements ShouldQueue
{
    use Queueable;

    /** Retry a few times with growing backoff, then give up (and log). */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $userId,
        public string $event,
        public array $payload,
    ) {}

    public function handle(N8nDispatcher $dispatcher): void
    {
        $user = User::find($this->userId);

        if (! $user instanceof User) {
            return;
        }

        $integration = $user->integrations()
            ->where('provider', 'n8n')
            ->first();

        if (! $integration instanceof UserIntegration) {
            return; // Disconnected since the event was queued — nothing to do.
        }

        // Throws on connection failure (→ retry). Also re-checks the URL is
        // public (SSRF guard) and throws if it no longer is.
        $status = $dispatcher->post($integration, $this->event, $this->payload);

        // Retry server errors; a 4xx means n8n rejected it — don't hammer.
        if ($status >= 500) {
            throw new RuntimeException("n8n webhook returned HTTP {$status}");
        }
    }
}
