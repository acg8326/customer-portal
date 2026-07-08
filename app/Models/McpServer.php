<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user connection to a remote MCP (Model Context Protocol) server, whose
 * tools Claude can call natively during a chat.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $url
 * @property string|null $auth_token
 * @property bool $enabled
 */
class McpServer extends Model
{
    protected $fillable = [
        'name',
        'url',
        'auth_token',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
