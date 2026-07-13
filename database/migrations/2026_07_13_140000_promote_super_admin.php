<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: promote the designated super administrator. Runs on deploy
 * so the production account is promoted without re-seeding.
 */
return new class extends Migration
{
    private const SUPER_ADMIN_EMAIL = 'alex.gordo@cwglobalpeople.com';

    public function up(): void
    {
        DB::table('users')
            ->where('email', self::SUPER_ADMIN_EMAIL)
            ->update(['role' => User::ROLE_SUPER_ADMIN]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', self::SUPER_ADMIN_EMAIL)
            ->update(['role' => User::ROLE_ADMIN]);
    }
};
