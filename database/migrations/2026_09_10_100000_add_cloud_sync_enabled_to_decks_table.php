<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-deck cloud sync (spec 2026-09-10). The flag mirrors the server's
     * slot ledger and is overwritten from it after every sync run; it is
     * false until the user enables a deck. The match quota is gone, so its
     * bookkeeping columns and parked quota_exceeded rejections go with it.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('decks', 'cloud_sync_enabled')) {
            Schema::table('decks', function (Blueprint $table) {
                $table->boolean('cloud_sync_enabled')->default(false)->after('synced_at');
            });
        }

        if (Schema::hasColumn('sync_state', 'quota_limit')) {
            Schema::table('sync_state', function (Blueprint $table) {
                $table->dropColumn(['quota_limit', 'quota_used']);
            });
        }

        DB::table('sync_rejections')->where('reason', 'quota_exceeded')->delete();
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn('cloud_sync_enabled');
        });

        Schema::table('sync_state', function (Blueprint $table) {
            $table->integer('quota_limit')->nullable();
            $table->integer('quota_used')->nullable();
        });
    }
};
