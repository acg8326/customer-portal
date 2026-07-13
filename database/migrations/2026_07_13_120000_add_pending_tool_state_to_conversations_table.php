<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paused tool-loop state for the hard approval gate: when the assistant
     * requests a destructive tool call, the turn pauses here (encrypted JSON —
     * it carries tool inputs/results) until the user clicks Approve or Cancel.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('pending_tool_state')->nullable()->after('auto_approve');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('pending_tool_state');
        });
    }
};
