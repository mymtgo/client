<?php

namespace App\Actions;

use App\Models\MtgoMatch;
use App\Models\Player;

class DetermineMatchArchetypes
{
    public static function run(MtgoMatch $match)
    {
        $matchArchetypes = [];

        $player = $match->games->first()->localPlayers->first();

        $playerDeck = $player->pivot->deck_json;
        $archetype = DetermineDeckArchetype::run(collect($playerDeck), $match->format);

        if ($archetype) {
            $matchArchetypes[] = [
                'archetype_id' => $archetype['archetype_id'],
                'confidence' => $archetype['confidence'],
                'player_id' => $player->id,
            ];
        }

        $opponentDecks = [];

        foreach ($match->games as $game) {
            $opponents = $game->opponents->filter(
                fn (Player $player) => ! $player->pivot->is_local
            );

            foreach ($opponents as $opponent) {
                $opponentDecks[$opponent->id] = $opponentDecks[$opponent->id] ?? [];

                $cards = collect($opponent->pivot->deck_json)->values();

                $opponentDecks[$opponent->id] = [
                    ...$opponentDecks[$opponent->id],
                    ...$cards->toArray(),
                ];
            }
        }

        foreach ($opponentDecks as $opponentId => $opponentCards) {
            $cards = collect($opponentCards)->groupBy('mtgo_id')->map(function ($cards) {
                return [
                    'mtgo_id' => $cards[0]['mtgo_id'],
                    'quantity' => min(4, $cards->sum('quantity')),
                ];
            });

            $archetype = DetermineDeckArchetype::run($cards, $match->format);

            if ($archetype) {
                $matchArchetypes[] = [
                    'archetype_id' => $archetype['archetype_id'],
                    'confidence' => $archetype['confidence'],
                    'player_id' => $opponentId,
                ];
            }
        }

        $match->archetypes()->delete();

        $match->archetypes()->createMany($matchArchetypes);
    }
}
