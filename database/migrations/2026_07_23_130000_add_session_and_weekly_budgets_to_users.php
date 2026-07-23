<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more per-user rolling usage windows, additional to the existing
 * "period" window (token_budget_used / token_budget_started_at / token_limit):
 *
 *  - session: rolls from the user's first message in a session; resets
 *    usage.session_hours after that start.
 *  - weekly: rolls the same way; resets usage.weekly_days after that start.
 *
 * Each tier gets its own used/started_at pair (an independent rolling
 * window) plus a nullable per-user override column with token_limit's exact
 * semantics: null = inherit the workspace default for that tier, 0 =
 * unlimited for this user, positive = this user's cap for that tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('session_budget_used')->default(0)->after('token_budget_started_at');
            $table->timestamp('session_budget_started_at')->nullable()->after('session_budget_used');

            $table->unsignedBigInteger('weekly_budget_used')->default(0)->after('session_budget_started_at');
            $table->timestamp('weekly_budget_started_at')->nullable()->after('weekly_budget_used');

            $table->unsignedBigInteger('session_token_limit')->nullable()->after('token_limit');
            $table->unsignedBigInteger('weekly_token_limit')->nullable()->after('session_token_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'session_budget_used',
                'session_budget_started_at',
                'weekly_budget_used',
                'weekly_budget_started_at',
                'session_token_limit',
                'weekly_token_limit',
            ]);
        });
    }
};
