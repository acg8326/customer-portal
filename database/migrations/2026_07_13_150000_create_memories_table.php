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

    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
