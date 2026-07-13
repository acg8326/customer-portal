<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\MemoryCurator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Refreshes the user's automatic memory from a conversation's new messages
 * (dispatched from finalizeTurn every ANTHROPIC_MEMORY_EVERY messages).
 * Failures are reported and swallowed — the next threshold crossing retries.
 */
class UpdateUserMemory implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $conversationId) {}

    public function handle(MemoryCurator $curator): void
    {
        $conversation = Conversation::find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        try {
            $curator->update($conversation);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
