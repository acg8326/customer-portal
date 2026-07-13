<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Maintains a user's automatic memory: a short, user-editable list of durable
 * facts distilled from their conversations by a cheap model call. The list is
 * injected into the chat system prompt (ChatController::buildSystemPrompt)
 * and fully manageable in Settings → Profile.
 *
 * Mirrors ConversationCompactor's shape: a synchronous service invoked from
 * a queued job (UpdateUserMemory), charging the owner's token budget.
 */
class MemoryCurator
{
    /**
     * Revise the user's memory from this conversation's new messages.
     * Throws on failure (the job reports and swallows).
     */
    public function update(Conversation $conversation): void
    {
        $user = $conversation->user;

        if ($user === null || ! $user->memory_enabled) {
            return;
        }

        $apiKey = (string) config('services.anthropic.key');

        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $messages = $conversation->messages()
            ->where('id', '>', $conversation->memory_through_id ?? 0)
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        $transcript = Str::limit(
            $messages->map(fn (Message $m): string => ($m->role === 'assistant' ? 'Assistant' : 'User').': '.trim((string) $m->content))
                ->implode("\n\n"),
            (int) config('services.anthropic.memory.max_transcript_chars', 12000),
            "\n[excerpt truncated]",
        );

        $existing = $user->memories()->orderBy('id')->pluck('content');

        $input = "Current memory list:\n"
            .($existing->isEmpty() ? '(empty)' : '- '.$existing->implode("\n- "))
            ."\n\nNew conversation excerpt:\n\n".$transcript;

        $client = new Client(apiKey: $apiKey);

        $response = $client->messages->create(
            maxTokens: 1024,
            messages: [['role' => 'user', 'content' => $input]],
            model: (string) config('services.anthropic.memory.model', 'claude-haiku-4-5'),
            system: (string) config('services.anthropic.memory.prompt'),
        );

        $raw = '';

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock) {
                $raw .= $block->text;
            }
        }

        $items = self::parse($raw);

        // Replace wholesale inside a transaction — the model returned the
        // full revised list, so partial writes would corrupt it.
        DB::transaction(function () use ($user, $items, $conversation, $messages): void {
            $user->memories()->delete();

            foreach ($items as $content) {
                $user->memories()->create(['content' => $content]);
            }

            $conversation->memory_through_id = (int) $messages->last()->id;
            $conversation->timestamps = false;
            $conversation->save();
        });

        // The extraction call costs tokens — charge the owner's budget.
        app(TokenBudget::class)->record(
            $user,
            $response->usage->inputTokens + $response->usage->outputTokens,
        );
    }

    /**
     * Parse the model's revised list: one fact per line, bounded in count and
     * length; "NONE" (or noise) yields an empty list.
     *
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $maxItems = (int) config('services.anthropic.memory.max_items', 15);
        $maxChars = (int) config('services.anthropic.memory.max_item_chars', 200);

        $items = [];

        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $line = trim(ltrim(trim($line), '-*• '));

            if ($line === '' || strcasecmp($line, 'NONE') === 0) {
                continue;
            }

            $items[] = Str::limit($line, $maxChars, '…');

            if (count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }
}
