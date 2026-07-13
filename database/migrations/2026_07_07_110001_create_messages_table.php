<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->longText('content');
            // Extended-thinking summary shown in the UI's collapsible block.
            $table->text('thinking')->nullable();
            // Thumbs rating: 1 up / -1 down / null none.
            $table->smallInteger('feedback')->nullable();
            // Uploaded files metadata (name/mime/size/storage path).
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
