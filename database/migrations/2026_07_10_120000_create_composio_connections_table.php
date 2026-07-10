<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composio_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('toolkit');                       // e.g. 'slack'
            $table->string('connected_account_id')->nullable();
            $table->string('status')->default('initiated');  // initiated | active
            $table->timestamps();

            $table->unique(['user_id', 'toolkit']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composio_connections');
    }
};
