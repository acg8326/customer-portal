<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal access tokens for the LLM gateway — a developer generates one and
 * puts it in Claude Code (ANTHROPIC_AUTH_TOKEN). Only the SHA-256 hash is
 * stored; the plaintext is shown once at creation. `last_four` is a display
 * hint so a developer can tell their tokens apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('last_four', 4)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_tokens');
    }
};
