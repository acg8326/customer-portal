<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auto-learned facts about a user, injected into the chat system
        // prompt and fully editable by the user in Settings → Profile.
        Schema::create('memories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('content', 500);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            // Per-user opt-out of automatic memory.
            $table->boolean('memory_enabled')->default(true);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            // Last message id already folded into the user's memory — the
            // extraction job only reads messages after this.
            $table->unsignedBigInteger('memory_through_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('memory_enabled'));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('memory_through_id'));
    }
};
