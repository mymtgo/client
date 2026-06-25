<?php

namespace App\Actions;

use App\Actions\Archetypes\ResolveMergedArchetype;
use App\Jobs\DownloadArchetypeDecklists;
use App\Models\Archetype;
use App\Models\MtgoMatch;

class DetermineMatchArchetypes
{
    public static function run(MtgoMatch $match): void
    {
        $matchArchetypes = [];

        $firstGame = $match->games->first();

        if (! $firstGame) {
            return;
        }

        // ── Local archetype ───────────────────────────────────────────────────
        // Prefer the archetype_id linked via the match's deck version (confidence 1.0).
        // Fall back to detecting from the local game_decks row of the first game.
        $deckArchetypeId = $match->deckVersion?->deck?->archetype_id;

        if ($deckArchetypeId) {
            $resolved = ResolveMergedArchetype::run($deckArchetypeId, null);
            $matchArchetypes[] = [
                'archetype_id' => $resolved['archetype_id'],
                'archetype_deck_id' => $resolved['archetype_deck_id'],
                'confidence' => 1.0,
                'is_opponent' => false,
            ];
        } else {
            $localDeckJson = $firstGame->decks()->where('is_opponent', false)->value('deck_json');

            if ($localDeckJson !== null) {
                $archetype = DetermineDeckArchetype::run(
                    collect($localDeckJson),
                    $match->format,
                    $match->id,
                    $match->account_id,
                );

                if ($archetype) {
                    $resolved = ResolveMergedArchetype::run(
                        $archetype['archetype_id'],
                        $archetype['archetype_deck_id'] ?? null,
                    );
                    $matchArchetypes[] = [
                        'archetype_id' => $resolved['archetype_id'],
                        'archetype_deck_id' => $resolved['archetype_deck_id'],
                        'confidence' => $archetype['confidence'],
                        'is_opponent' => false,
                    ];
                }
            }
        }

        // ── Opponent archetype ────────────────────────────────────────────────
        // Aggregate all opponent deck_json rows across every game into a single
        // card list (1v1 invariant: there is exactly one opponent per match).
        $opponentCards = [];

        foreach ($match->games as $game) {
            $opponentDeckJson = $game->decks()->where('is_opponent', true)->value('deck_json');

            if ($opponentDeckJson) {
                $opponentCards = [
                    ...$opponentCards,
                    ...collect($opponentDeckJson)->values()->toArray(),
                ];
            }
        }

        if (! empty($opponentCards)) {
            $cards = collect($opponentCards)->groupBy('mtgo_id')->map(function ($cards) {
                return [
                    'mtgo_id' => $cards[0]['mtgo_id'],
                    'quantity' => min(4, $cards->sum('quantity')),
                ];
            });

            $archetype = DetermineDeckArchetype::run(
                $cards,
                $match->format,
                $match->id,
                $match->opponent_id,
            );

            if (! $archetype) {
                $homebrewId = Archetype::query()
                    ->where('uuid', Archetype::HOMEBREW_UUID)
                    ->value('id');

                if ($homebrewId !== null) {
                    $archetype = ['archetype_id' => $homebrewId, 'archetype_deck_id' => null, 'confidence' => 0];
                }
            }

            if ($archetype) {
                $resolved = ResolveMergedArchetype::run(
                    $archetype['archetype_id'],
                    $archetype['archetype_deck_id'] ?? null,
                );
                $matchArchetypes[] = [
                    'archetype_id' => $resolved['archetype_id'],
                    'archetype_deck_id' => $resolved['archetype_deck_id'],
                    'confidence' => $archetype['confidence'],
                    'is_opponent' => true,
                ];
            }
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
