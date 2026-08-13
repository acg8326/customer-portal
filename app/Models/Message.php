<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property string|null $thinking
 * @property int|null $feedback
 * @property array<int, array{name: string, mime: string, size: int, path: string, pasted?: bool, lines?: int, snippet?: string}>|null $attachments Uploaded files; `pasted` marks text captured from a long paste.
 * @property array<int, array{tool: string, source: string, kind: string, fix: string, detail: string}>|null $tool_errors Connected-tool failures during this turn, shown as error cards.
 */
#[Fillable(['role', 'content', 'thinking', 'attachments', 'tool_errors'])]
class Message extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'tool_errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
