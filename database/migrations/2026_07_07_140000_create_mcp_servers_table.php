<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Which catalog entry (config integrations.mcp_catalog) this server
            // was created from; null for hand-added ("custom") servers.
            $table->string('catalog_key')->nullable();
            $table->string('url', 2048);
            // Bearer token for the MCP server, encrypted at rest.
            $table->text('auth_token')->nullable();
            // 'token' = static bearer token (paste), 'oauth' = OAuth 2.1 flow.
            $table->string('auth_type')->default('token');
            // OAuth client identity — from Dynamic Client Registration (or manual).
            $table->string('oauth_client_id')->nullable();
            $table->text('oauth_client_secret')->nullable();
            // Tokens obtained from the authorization server (encrypted at rest).
            $table->text('oauth_access_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();
            // Discovered endpoints + scopes.
            $table->text('oauth_metadata')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
