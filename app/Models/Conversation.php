<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $project_id
 * @property int|null $skill_id
 * @property string $title
 * @property string $model
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string|null $summary
 * @property int|null $summary_through_id
 * @property bool $auto_approve
 * @property bool $starred
 * @property int|null $memory_through_id
 * @property string|null $share_token
 * @property array<string, mixed>|null $pending_tool_state
 */
#[Fillable(['title', 'model', 'project_id'])]
class Conversation extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_approve' => 'boolean',
            'starred' => 'boolean',
            // Paused tool-loop state (hard approval gate). Encrypted — it
            // carries tool inputs and intermediate results.
            'pending_tool_state' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
