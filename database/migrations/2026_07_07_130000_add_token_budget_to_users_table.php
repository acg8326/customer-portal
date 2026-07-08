<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tokens consumed in the current billing window.
            $table->unsignedBigInteger('token_budget_used')->default(0);
            // When the current window started (null = never used / reset on first use).
            $table->timestamp('token_budget_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['token_budget_used', 'token_budget_started_at']);
        });
    }
};
