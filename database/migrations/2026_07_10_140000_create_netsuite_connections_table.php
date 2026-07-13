<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user NetSuite connection using Token-Based Authentication (TBA) —
     * the OAuth 1.0a scheme NetSuite uses for server-to-server access to
     * SuiteTalk REST + SuiteQL. Unlike Composio (OAuth 2.0 only), the user
     * brings the four TBA values generated in NetSuite (consumer key/secret from
     * the Integration record, token id/secret from the Access Token) plus the
     * Account ID. All four secrets are stored encrypted (model casts).
     */
    public function up(): void
    {
        Schema::create('netsuite_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('account_id'); // realm, e.g. 1234567 or 1234567_SB1
            // 'tba' (Token-Based Auth) or 'oauth2'. TBA secrets only exist for
            // TBA connections; OAuth 2.0 fields only for oauth2 (all encrypted).
            $table->string('auth_type')->default('tba');
            $table->text('consumer_key')->nullable();
            $table->text('consumer_secret')->nullable();
            $table->text('token_id')->nullable();
            $table->text('token_secret')->nullable();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status')->default('active'); // active | error
            $table->text('last_error')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('user_id'); // one NetSuite account per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netsuite_connections');
    }
};
