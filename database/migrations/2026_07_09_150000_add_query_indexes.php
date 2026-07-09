<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the foreign-key columns we filter/join on. PostgreSQL does not create
 * an index for a foreign key automatically (unlike MySQL), so these matter for
 * the hot paths: fetching a conversation's messages, chat search, per-user MCP
 * servers, and per-user skills. (conversations.user_id and projects.user_id are
 * already covered by existing (user_id, updated_at) composite indexes.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index('conversation_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index('project_id');
        });

        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
        });

        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
