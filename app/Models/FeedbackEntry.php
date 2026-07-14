<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A written feedback or suggestion entry submitted from the dashboard —
 * the free-text complement to the thumbs up/down on chat replies. Read by
 * the super admin on the dashboard's feedback card.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type feedback | suggestion
 * @property string $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'message'])]
class FeedbackEntry extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
