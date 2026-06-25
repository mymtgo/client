<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('match_archetypes')) {
            return;
        }

        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->unsignedBigInteger('player_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->unsignedBigInteger('player_id')->nullable(false)->change();
        });
    }
};
