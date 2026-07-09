<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete the user-created content so an accidental delete is recoverable:
 * conversations, projects, and skills. (MCP servers / integrations stay hard
 * delete — they hold secrets and are trivial to reconnect; users stay hard
 * delete to keep the unique-email constraint simple.)
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['conversations', 'projects', 'skills'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['conversations', 'projects', 'skills'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
