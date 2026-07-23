<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single request through the chat or gateway path — who, when, which
 * model, tokens, latency, and outcome (Analytics → Logs). Append-only: rows
 * are created once and never updated, so there's no updated_at.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $surface
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int $status
 * @property int|null $latency_ms
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'surface', 'model', 'input_tokens', 'output_tokens', 'status', 'latency_ms'])]
class RequestLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
