<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds conversation compaction: a running summary of the earlier messages
     * plus the id of the last message folded into it. Once set, only messages
     * newer than `summary_through_id` are replayed to the API each turn — the
     * summary stands in for everything before it, keeping context (and cost)
     * bounded on long chats, the way Claude's /compact does.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->text('summary')->nullable()->after('completion_tokens');
            $table->unsignedBigInteger('summary_through_id')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['summary', 'summary_through_id']);
        });
    }
};
