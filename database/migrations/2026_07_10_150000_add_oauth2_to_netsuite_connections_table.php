<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a second NetSuite auth method: OAuth 2.0 Authorization Code Grant,
     * alongside the existing Token-Based Auth (TBA). `auth_type` picks which one
     * a connection uses. The TBA columns become nullable (an OAuth2 connection
     * doesn't set them), and OAuth2 stores the app credentials + the tokens we
     * receive (access token is short-lived and refreshed via the refresh token).
     */
    public function up(): void
    {
        Schema::table('netsuite_connections', function (Blueprint $table): void {
            $table->string('auth_type')->default('tba')->after('account_id');

            // OAuth 2.0 app credentials + issued tokens (all encrypted by casts).
            $table->text('client_id')->nullable()->after('token_secret');
            $table->text('client_secret')->nullable()->after('client_id');
            $table->text('access_token')->nullable()->after('client_secret');
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
        });

        // TBA secrets are only set for TBA connections — make them optional.
        Schema::table('netsuite_connections', function (Blueprint $table): void {
            $table->text('consumer_key')->nullable()->change();
            $table->text('consumer_secret')->nullable()->change();
            $table->text('token_id')->nullable()->change();
            $table->text('token_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('netsuite_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'auth_type', 'client_id', 'client_secret',
                'access_token', 'refresh_token', 'token_expires_at',
            ]);
        });
    }
};
