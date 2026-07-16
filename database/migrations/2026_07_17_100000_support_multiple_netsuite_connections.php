<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user may now connect several NetSuite accounts at once: uniqueness moves
 * from "one connection per user" to "one connection per user per account".
 * Each connection gets a display label and one is the user's default; a chat
 * conversation can pin the connection its NetSuite queries run against.
 *
 * Steps are guarded so a partially-applied run (MySQL DDL isn't
 * transactional) can simply be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('netsuite_connections', 'label')) {
            Schema::table('netsuite_connections', function (Blueprint $table) {
                $table->string('label', 60)->nullable()->after('account_id');
            });
        }

        if (! Schema::hasColumn('netsuite_connections', 'is_default')) {
            Schema::table('netsuite_connections', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('label');
            });
        }

        // Add the composite unique BEFORE dropping the old one: on MySQL the
        // user_id foreign key needs a covering index at all times, and the
        // composite's leftmost column keeps it satisfied.
        if (! Schema::hasIndex('netsuite_connections', ['user_id', 'account_id'])) {
            Schema::table('netsuite_connections', function (Blueprint $table) {
                $table->unique(['user_id', 'account_id']);
            });
        }

        if (Schema::hasIndex('netsuite_connections', 'netsuite_connections_user_id_unique')) {
            Schema::table('netsuite_connections', function (Blueprint $table) {
                $table->dropUnique(['user_id']);
            });
        }

        // Pre-migration rows are one-per-user, so each becomes its default.
        DB::table('netsuite_connections')->where('is_default', false)->update(['is_default' => true]);

        if (! Schema::hasColumn('conversations', 'netsuite_connection_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                // Which NetSuite account this chat's queries run against; null
                // falls back to the user's default connection.
                $table->foreignId('netsuite_connection_id')
                    ->nullable()
                    ->constrained('netsuite_connections')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('netsuite_connection_id');
        });

        Schema::table('netsuite_connections', function (Blueprint $table) {
            // Fails if a user still has several connections — remove extras first.
            $table->unique('user_id');
            $table->dropUnique(['user_id', 'account_id']);
            $table->dropColumn(['label', 'is_default']);
        });
    }
};
