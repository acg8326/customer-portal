<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('prompt_tokens')->default(0)->after('model');
            $table->unsignedBigInteger('completion_tokens')->default(0)->after('prompt_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['prompt_tokens', 'completion_tokens']);
        });
    }
};
