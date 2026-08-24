<?php

namespace App\Actions\Archetypes;

use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Collection;

class AggregateOpponentCards
{
    /**
     * Aggregate every non-local player's revealed cards across a match's games.
     *
     * Quantities are summed per mtgo_id and capped at 4, matching the deck
     * limit. Reads only `game_player.deck_json` — no card resolution, no HTTP —
     * so this is safe to call from a polling request.
     *
     * @return array<int, Collection<int, array{mtgo_id: int, quantity: int}>> keyed by player id
     */
    public static function run(MtgoMatch $match): array
    {
        $match->loadMissing('games.players');

        $decksByPlayer = [];

        foreach ($match->games as $game) {
            $opponents = $game->players->filter(fn (Player $player) => ! $player->pivot->is_local);

            foreach ($opponents as $opponent) {
                $cards = collect($opponent->pivot->deck_json ?? [])->values()->toArray();

                $decksByPlayer[$opponent->id] = [
                    ...($decksByPlayer[$opponent->id] ?? []),
                    ...$cards,
                ];
            }
        }

        $aggregated = [];

        foreach ($decksByPlayer as $playerId => $cards) {
            $collapsed = collect($cards)
                ->filter(fn ($card) => ! empty($card['mtgo_id']))
                ->groupBy('mtgo_id')
                ->map(fn (Collection $group) => [
                    'mtgo_id' => (int) $group->first()['mtgo_id'],
                    'quantity' => min(4, (int) $group->sum('quantity')),
                ])
                ->values();

            if ($collapsed->isEmpty()) {
                continue;
            }

            $aggregated[$playerId] = $collapsed;
        }

        return $aggregated;
    }
}
