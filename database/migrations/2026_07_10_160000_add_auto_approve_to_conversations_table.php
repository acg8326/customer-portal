<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-conversation "auto-approve tool actions" flag. When on, the
     * confirm-before-destructive-actions guardrail is omitted from the system
     * prompt so the assistant acts without asking each time. Default off (safe).
     * Set from the chat's session toggle on each turn.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->boolean('auto_approve')->default(false)->after('summary_through_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('auto_approve');
        });
    }
};
