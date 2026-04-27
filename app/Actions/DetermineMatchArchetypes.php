<?php

namespace App\Actions;

use App\Jobs\DownloadArchetypeDecklists;
use App\Models\Archetype;
use App\Models\MtgoMatch;
use App\Models\Player;

class DetermineMatchArchetypes
{
    public static function run(MtgoMatch $match): void
    {
        $matchArchetypes = [];

        $firstGame = $match->games->first();

        if (! $firstGame) {
            return;
        }

        $player = $firstGame->localPlayers->first();

        if ($player) {
            $deckArchetypeId = $match->deckVersion?->deck?->archetype_id;

            if ($deckArchetypeId) {
                $matchArchetypes[] = [
                    'archetype_id' => $deckArchetypeId,
                    'confidence' => 1.0,
                    'player_id' => $player->id,
                ];
            } else {
                $playerDeck = $player->pivot->deck_json;
                $archetype = DetermineDeckArchetype::run(collect($playerDeck), $match->format, $match->id, $player->id);

                if ($archetype) {
                    $matchArchetypes[] = [
                        'archetype_id' => $archetype['archetype_id'],
                        'confidence' => $archetype['confidence'],
                        'player_id' => $player->id,
                    ];
                }
            }
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

        $homebrewId = null;

        foreach ($opponentDecks as $opponentId => $opponentCards) {
            $cards = collect($opponentCards)->groupBy('mtgo_id')->map(function ($cards) {
                return [
                    'mtgo_id' => $cards[0]['mtgo_id'],
                    'quantity' => min(4, $cards->sum('quantity')),
                ];
            });

            $archetype = DetermineDeckArchetype::run($cards, $match->format, $match->id, $opponentId);

            if (! $archetype) {
                $homebrewId ??= Archetype::query()
                    ->where('uuid', Archetype::HOMEBREW_UUID)
                    ->value('id');

                if ($homebrewId === null) {
                    continue;
                }

                $archetype = ['archetype_id' => $homebrewId, 'confidence' => 0];
            }

            $matchArchetypes[] = [
                'archetype_id' => $archetype['archetype_id'],
                'confidence' => $archetype['confidence'],
                'player_id' => $opponentId,
            ];
        }

        $match->archetypes()->delete();

        $match->archetypes()->createMany($matchArchetypes);

        foreach ($matchArchetypes as $matchArchetype) {
            $archetypeModel = Archetype::query()->find($matchArchetype['archetype_id']);

            if ($archetypeModel?->is_fallback) {
                continue;
            }

            DownloadArchetypeDecklists::dispatch($matchArchetype['archetype_id']);
        }
    }
}
