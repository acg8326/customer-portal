<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            // Which catalog entry (config integrations.mcp_catalog) this server
            // was created from, so its card can show connected/manage state.
            // Null for hand-added ("custom") servers.
            $table->string('catalog_key')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->dropColumn('catalog_key');
        });
    }
};
