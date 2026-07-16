<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $account_id
 * @property string|null $label
 * @property bool $is_default
 * @property string $auth_type
 * @property string|null $consumer_key
 * @property string|null $consumer_secret
 * @property string|null $token_id
 * @property string|null $token_secret
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $last_used_at
 */
#[Fillable(['account_id', 'label', 'auth_type', 'consumer_key', 'consumer_secret', 'token_id', 'token_secret', 'client_id', 'client_secret', 'status'])]
#[Hidden(['consumer_key', 'consumer_secret', 'token_id', 'token_secret', 'client_id', 'client_secret', 'access_token', 'refresh_token'])]
class NetsuiteConnection extends Model
{
    public const AUTH_TBA = 'tba';

    public const AUTH_OAUTH2 = 'oauth2';

    /**
     * All secrets (TBA keys + OAuth2 client creds + issued tokens) are encrypted
     * at rest — they must never appear in logs, API responses, or the database
     * in plain text.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'token_id' => 'encrypted',
            'token_secret' => 'encrypted',
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }

    /**
     * What the UI (and activity labels) call this account: the user's label,
     * falling back to the raw NetSuite account id.
     */
    public function displayLabel(): string
    {
        return filled($this->label) ? (string) $this->label : $this->account_id;
    }

    public function isOauth2(): bool
    {
        return $this->auth_type === self::AUTH_OAUTH2;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
