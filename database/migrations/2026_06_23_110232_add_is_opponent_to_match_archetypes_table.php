<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('match_archetypes') || Schema::hasColumn('match_archetypes', 'is_opponent')) {
            return;
        }

        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->boolean('is_opponent')->default(false)->after('player_id');
        });
    }

    public function down(): void
    {
        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->dropColumn('is_opponent');
        });
    }
};
