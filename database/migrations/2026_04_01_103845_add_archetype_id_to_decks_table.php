<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->foreignId('archetype_id')->nullable()->after('cover_id')->constrained('archetypes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropForeign(['archetype_id']);
            $table->dropColumn('archetype_id');
        });
    }
};
