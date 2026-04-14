<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->dropForeign(['archetype_id']);
            $table->foreign('archetype_id')
                ->references('id')
                ->on('archetypes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->dropForeign(['archetype_id']);
            $table->foreign('archetype_id')
                ->references('id')
                ->on('archetypes');
        });
    }
};
