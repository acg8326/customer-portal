<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Org-wide runtime settings editable in the UI (super admin) — each
        // row overrides the matching config/.env default (see AppSettings).
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
