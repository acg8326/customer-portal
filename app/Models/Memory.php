<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One auto-learned fact about a user (see MemoryCurator). Injected into the
 * chat system prompt; the user can edit or delete each one in Settings.
 *
 * @property int $id
 * @property int $user_id
 * @property string $content
 */
#[Fillable(['content'])]
class Memory extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
