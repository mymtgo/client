<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sideboard_guides')) {
            Schema::create('sideboard_guides', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('deck_id')->constrained('decks')->cascadeOnDelete();
                $table->foreignId('archetype_id')->constrained('archetypes')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['deck_id', 'archetype_id']);
            });
        }

        if (! Schema::hasTable('sideboard_guide_cards')) {
            Schema::create('sideboard_guide_cards', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sideboard_guide_id')->constrained('sideboard_guides')->cascadeOnDelete();
                $table->string('oracle_id');
                $table->string('direction', 8);
                $table->unsignedTinyInteger('quantity');
                $table->timestamps();

                $table->unique(['sideboard_guide_id', 'oracle_id', 'direction'], 'sideboard_guide_cards_unique_entry');
            });
        }

        $this->backfillFromNotes();
    }

    public function down(): void
    {
        Schema::dropIfExists('sideboard_guide_cards');
        Schema::dropIfExists('sideboard_guides');
    }

    /**
     * Every (deck, archetype) pair that already has notes gets an empty guide,
     * so existing notes show up in the guides listing straight away. Pairs that
     * already have a guide are skipped, which is what makes a rerun safe.
     */
    private function backfillFromNotes(): void
    {
        if (! Schema::hasTable('deck_archetype_notes')) {
            return;
        }

        $now = now();

        $pairs = DB::table('deck_archetype_notes as n')
            ->leftJoin('sideboard_guides as g', function (JoinClause $join): void {
                $join->on('g.deck_id', '=', 'n.deck_id')
                    ->on('g.archetype_id', '=', 'n.archetype_id');
            })
            ->whereNull('g.id')
            ->selectRaw('DISTINCT n.deck_id, n.archetype_id')
            ->get();

        foreach ($pairs as $pair) {
            DB::table('sideboard_guides')->insert([
                'deck_id' => $pair->deck_id,
                'archetype_id' => $pair->archetype_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
