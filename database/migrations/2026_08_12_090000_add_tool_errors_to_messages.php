<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed tool calls for the turn, so the error card survives a page reload.
 * Without this the explanation only exists in the live SSE stream — and the
 * moment you refresh to go fix the permission, the thing telling you WHICH
 * permission is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->json('tool_errors')->nullable()->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('tool_errors');
        });
    }
};
