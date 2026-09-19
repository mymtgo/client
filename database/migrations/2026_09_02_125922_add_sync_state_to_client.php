<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sync bookkeeping. `synced_hash` is the hash last confirmed present on
     * the server; comparing `updated_at > synced_at` is what makes dirtiness
     * a column comparison instead of a bundle rebuild.
     */
    public function up(): void
    {
        foreach (['matches', 'decks', 'leagues'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('synced_hash', 64)->nullable();
                $blueprint->dateTime('synced_at')->nullable();
            });
        }

        Schema::create('sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->unique();
            $table->dateTime('last_synced_at')->nullable();
            $table->integer('canonical_version');
            $table->unsignedBigInteger('full_cursor')->nullable();
            $table->integer('quota_limit')->nullable();
            $table->integer('quota_used')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_rejections', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('client_id');
            $table->string('reason', 32);
            $table->char('hash', 64);
            $table->timestamps();

            $table->unique(['type', 'client_id']);
        });
    }

    public function down(): void
    {
        foreach (['matches', 'decks', 'leagues'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['synced_hash', 'synced_at']);
            });
        }

        Schema::dropIfExists('sync_state');
        Schema::dropIfExists('sync_rejections');
    }
};
