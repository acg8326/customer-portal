<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prompt-cache accounting per request, matching the columns conversations
// already carries. Without these, gateway traffic (Claude Code and friends)
// contributed nothing to the cache hit-rate and savings figures on Analytics —
// despite being the surface that caches hardest.
//
// input_tokens keeps its narrower meaning on both surfaces: the UNCACHED
// remainder of the prompt. The whole prompt is input + read + write.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->unsignedInteger('cache_read_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('cache_read_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropColumn(['cache_read_tokens', 'cache_write_tokens']);
        });
    }
};
