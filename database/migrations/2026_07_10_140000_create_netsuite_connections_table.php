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
            $table->text('consumer_key');
            $table->text('consumer_secret');
            $table->text('token_id');
            $table->text('token_secret');
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
