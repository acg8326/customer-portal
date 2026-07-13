<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use App\Models\Conversation;
use App\Models\Message;
use RuntimeException;

/**
 * Condenses a conversation's transcript into a running summary stored on the
 * conversation, so earlier messages no longer need replaying each turn (see
 * ChatController::recentMessages()). Shared by the manual Compact button and
 * the automatic threshold-based compaction job.
 */
class ConversationCompactor
{
    /**
     * Summarize the conversation and persist the summary. Throws on failure.
     */
    public function compact(Conversation $conversation): string
    {
        $apiKey = (string) config('services.anthropic.key');

        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $messages = $conversation->messages()->orderBy('id')->get();

        // Need at least a full exchange to have anything worth compacting.
        if ($messages->count() < 2) {
            throw new RuntimeException('This conversation is too short to compact.');
        }

        $transcript = $messages
            ->map(fn (Message $m): string => ($m->role === 'assistant' ? 'Assistant' : 'User').': '.trim((string) $m->content))
            ->implode("\n\n");

        // Fold any prior summary in so repeated compaction stays cumulative.
        if (filled($conversation->summary)) {
            $transcript = "Summary of the conversation before this point:\n"
                .$conversation->summary."\n\n---\n\n".$transcript;
        }

        $client = new Client(apiKey: $apiKey);

        $message = $client->messages->create(
            maxTokens: (int) config('services.anthropic.max_tokens', 8192),
            messages: [
                ['role' => 'user', 'content' => "Transcript to compact:\n\n".$transcript],
            ],
            model: $conversation->model ?: (string) config('services.anthropic.model'),
            system: (string) config('services.anthropic.compact_prompt'),
        );

        $summary = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $summary .= $block->text;
            }
        }

        $summary = trim($summary);

        if ($summary === '') {
            throw new RuntimeException('The API returned an empty summary.');
        }

        $conversation->summary = $summary;
        $conversation->summary_through_id = (int) $messages->last()->id;
        $conversation->prompt_tokens += $message->usage->inputTokens;
        $conversation->completion_tokens += $message->usage->outputTokens;
        $conversation->save();

        // The summarization call costs tokens too — charge the owner's budget.
        if ($conversation->user !== null) {
            app(TokenBudget::class)->record(
                $conversation->user,
                $message->usage->inputTokens + $message->usage->outputTokens,
            );
        }

        return $summary;
    }
}
