<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A personal access token for the LLM gateway. Only the hash is persisted;
 * the plaintext exists once, at creation.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property string|null $last_four
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['name'])]
#[Hidden(['token_hash'])]
class GatewayToken extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a token for a user and return [model, plaintext]. The plaintext
     * is the only time the full secret exists — the caller must show it once.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $name): array
    {
        $prefix = (string) config('services.anthropic.gateway.token_prefix', 'aime');
        $secret = Str::random(40);
        $plaintext = $prefix.'_'.$secret;

        $token = new self;
        $token->user_id = $user->id;
        $token->name = $name;
        $token->token_hash = self::hash($plaintext);
        $token->last_four = substr($secret, -4);
        $token->save();

        return [$token, $plaintext];
    }

    /**
     * Resolve a plaintext token to its active (non-revoked) record, or null.
     */
    public static function findActive(string $plaintext): ?self
    {
        return self::query()
            ->where('token_hash', self::hash($plaintext))
            ->whereNull('revoked_at')
            ->first();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
