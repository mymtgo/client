<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\MatchArchetype;
use Illuminate\Support\Facades\DB;

class RemoveArchetypeVariant
{
    public static function run(Archetype $archetype, ArchetypeDeck $deck): void
    {
        DB::transaction(function () use ($deck) {
            MatchArchetype::where('archetype_deck_id', $deck->id)
                ->update(['archetype_deck_id' => null]);

            $deck->cards()->detach();
            $deck->delete();
        });
    }
}
