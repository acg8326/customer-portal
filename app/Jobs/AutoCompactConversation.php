<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ConversationCompactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compacts a conversation in the background once its replayed context crossed
 * the ANTHROPIC_AUTO_COMPACT_TOKENS threshold (dispatched from finalizeTurn).
 * Failures are logged and swallowed — the next threshold crossing retries.
 */
class AutoCompactConversation implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $conversationId) {}

    public function handle(ConversationCompactor $compactor): void
    {
        $conversation = Conversation::find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        try {
            $compactor->compact($conversation);

            Log::info('Auto-compacted conversation', [
                'conversation_id' => $conversation->id,
                'summary_through_id' => $conversation->summary_through_id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
