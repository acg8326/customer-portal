<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-user connection to an external tool (e.g. n8n).
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property array<string, mixed>|null $config
 * @property Carbon|null $connected_at
 */
class UserIntegration extends Model
{
    protected $fillable = [
        'provider',
        'config',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            // Whole config (webhook URL + secret) is encrypted at rest.
            'config' => 'encrypted:array',
            'connected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
