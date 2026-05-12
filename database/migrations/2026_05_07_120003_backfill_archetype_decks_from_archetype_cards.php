<?php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        self::backfill();
    }

    public function down(): void
    {
        // Irreversible: data backfill only, schema not altered here.
    }

    public static function backfill(): void
    {
        Archetype::query()
            ->whereDoesntHave('decks')
            ->whereHas('cards')
            ->with('cards')
            ->chunkById(100, function ($archetypes) {
                foreach ($archetypes as $archetype) {
                    DB::transaction(function () use ($archetype) {
                        $deck = ArchetypeDeck::create([
                            'uuid' => (string) Str::uuid(),
                            'archetype_id' => $archetype->id,
                            'seen_count' => 1,
                            'last_synced_at' => $archetype->decklist_downloaded_at,
                        ]);

                        $pivotData = [];
                        foreach ($archetype->cards as $card) {
                            $pivotData[$card->id] = [
                                'quantity' => $card->pivot->quantity,
                                'sideboard' => (bool) $card->pivot->sideboard,
                            ];
                        }

                        if (! empty($pivotData)) {
                            $deck->cards()->sync($pivotData);
                        }

                        DB::table('match_archetypes')
                            ->where('archetype_id', $archetype->id)
                            ->whereNull('archetype_deck_id')
                            ->update(['archetype_deck_id' => $deck->id]);
                    });
                }
            });
    }
};
