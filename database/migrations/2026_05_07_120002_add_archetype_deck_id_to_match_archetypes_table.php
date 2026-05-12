<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('match_archetypes') || Schema::hasColumn('match_archetypes', 'archetype_deck_id')) {
            return;
        }

        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->foreignId('archetype_deck_id')->nullable()->after('archetype_id')
                ->constrained('archetype_decks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archetype_deck_id');
        });
    }
};
