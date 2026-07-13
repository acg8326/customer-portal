<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standing chat instructions the user writes for themselves ("always answer
     * in Tagalog", "be terse"), appended to the system prompt as a
     * "## User preferences" section. Adjusts tone/format only — the prompt
     * carries a guard line so it can't override safety rules.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('chat_preferences')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chat_preferences');
        });
    }
};
