<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->default('New chat');
            $table->string('model');
            // Cumulative API usage for the conversation.
            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            // Compaction: summary standing in for messages up to this id.
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('summary_through_id')->nullable();
            // Per-session toggle: run destructive tool actions without asking.
            $table->boolean('auto_approve')->default(false);
            // Paused tool-loop state at the hard approval gate (encrypted cast).
            $table->text('pending_tool_state')->nullable();
            // Starred chats pin to the top of the sidebar.
            $table->boolean('starred')->default(false);
            // Last message id folded into the user's automatic memory.
            $table->unsignedBigInteger('memory_through_id')->nullable();
            // Team share link token (null = not shared).
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
