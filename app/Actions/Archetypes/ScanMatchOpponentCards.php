<?php

namespace App\Actions\Archetypes;

use App\Models\MtgoMatch;

class ScanMatchOpponentCards
{
    /**
     * Aggregate opponent cards from a match's game_decks (is_opponent=true),
     * sum quantities across games (capped at 4), resolve via API.
     *
     * @return array{cards: array, color_identity: string|null}|null
     */
    public static function run(MtgoMatch $match): ?array
    {
        $match->loadMissing('games.decks');

        $opponentCards = collect();

        foreach ($match->games as $game) {
            $opponentCards = $opponentCards->merge($game->opponentDeck()?->deck_json ?? []);
        }

        $aggregated = $opponentCards
            ->filter(fn ($card) => ! empty($card['mtgo_id']))
            ->groupBy('mtgo_id')
            ->map(fn ($group, $mtgoId) => [
                'mtgo_id' => (int) $mtgoId,
                'quantity' => min(4, $group->sum('quantity')),
                'sideboard' => false,
            ])
            ->values()
            ->all();

        if (empty($aggregated)) {
            return null;
        }

        return ResolveCardsFromDek::run($aggregated);
    }
}
