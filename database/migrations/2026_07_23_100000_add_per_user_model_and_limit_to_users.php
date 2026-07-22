<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user governance for the chat + the future LLM gateway:
 *  - assigned_model: pins this user to one model (null = workspace default /
 *    free choice from the allowed list).
 *  - token_limit: this user's own budget cap (null = inherit the workspace
 *    limit; 0 = unlimited for this user specifically).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('assigned_model')->nullable()->after('role');
            $table->unsignedBigInteger('token_limit')->nullable()->after('assigned_model');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['assigned_model', 'token_limit']);
        });
    }
};
