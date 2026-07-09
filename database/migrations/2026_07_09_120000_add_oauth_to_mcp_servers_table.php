<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            // 'token' = static bearer token (paste), 'oauth' = OAuth 2.1 flow.
            $table->string('auth_type')->default('token')->after('auth_token');

            // OAuth client identity — from Dynamic Client Registration (or manual).
            $table->string('oauth_client_id')->nullable()->after('auth_type');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');

            // Tokens obtained from the authorization server (encrypted at rest).
            $table->text('oauth_access_token')->nullable()->after('oauth_client_secret');
            $table->text('oauth_refresh_token')->nullable()->after('oauth_access_token');
            $table->timestamp('oauth_expires_at')->nullable()->after('oauth_refresh_token');

            // Discovered endpoints + scopes (authorization/token/registration URLs).
            $table->text('oauth_metadata')->nullable()->after('oauth_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->dropColumn([
                'auth_type',
                'oauth_client_id',
                'oauth_client_secret',
                'oauth_access_token',
                'oauth_refresh_token',
                'oauth_expires_at',
                'oauth_metadata',
            ]);
        });
    }
};
