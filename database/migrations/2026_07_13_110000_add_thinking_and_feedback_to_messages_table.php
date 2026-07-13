<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `thinking` — the model's summarized thought process for an assistant
     * reply (shown collapsed in the chat). `feedback` — thumbs rating on an
     * assistant reply: 1 (up), -1 (down), null (none).
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('thinking')->nullable()->after('content');
            $table->smallInteger('feedback')->nullable()->after('thinking');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['thinking', 'feedback']);
        });
    }
};
