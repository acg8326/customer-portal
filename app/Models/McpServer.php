<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user connection to a remote MCP (Model Context Protocol) server, whose
 * tools Claude can call natively during a chat.
 *
 * Authentication is either a static bearer token (`auth_type = 'token'`, pasted
 * by the user) or an OAuth 2.1 flow (`auth_type = 'oauth'`), where the token is
 * obtained via the authorization-code flow and auto-refreshed.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $catalog_key
 * @property string $url
 * @property string|null $auth_token
 * @property string $auth_type
 * @property string|null $oauth_client_id
 * @property string|null $oauth_client_secret
 * @property string|null $oauth_access_token
 * @property string|null $oauth_refresh_token
 * @property CarbonInterface|null $oauth_expires_at
 * @property array<string, mixed>|null $oauth_metadata
 * @property bool $enabled
 */
class McpServer extends Model
{
    protected $fillable = [
        'name',
        'catalog_key',
        'url',
        'auth_token',
        'auth_type',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
            'oauth_client_secret' => 'encrypted',
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_metadata' => 'encrypted:array',
            'oauth_expires_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this server authenticates via the OAuth flow (vs a static token).
     */
    public function usesOAuth(): bool
    {
        return $this->auth_type === 'oauth';
    }

    /**
     * Whether an OAuth access token has been obtained for this server.
     */
    public function oauthConnected(): bool
    {
        return $this->usesOAuth() && filled($this->oauth_access_token);
    }

    /**
     * Whether the stored OAuth access token is missing or (within a leeway
     * window) about to expire, and should be refreshed before use.
     */
    public function tokenExpired(int $leewaySeconds = 60): bool
    {
        if (blank($this->oauth_access_token)) {
            return true;
        }

        // No expiry recorded => treat as long-lived, never proactively refresh.
        if (! $this->oauth_expires_at instanceof CarbonInterface) {
            return false;
        }

        return $this->oauth_expires_at->subSeconds($leewaySeconds)->isPast();
    }

    /**
     * Discovered OAuth endpoints/scopes, or an empty array.
     *
     * @return array<string, mixed>
     */
    public function oauthMetadata(): array
    {
        return is_array($this->oauth_metadata) ? $this->oauth_metadata : [];
    }
}
