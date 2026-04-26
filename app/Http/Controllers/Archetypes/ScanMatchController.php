<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\ResolveCardsFromDek;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\JsonResponse;

class ScanMatchController extends Controller
{
    public function __invoke(MtgoMatch $match): JsonResponse
    {
        $opponentCards = collect();

        $match->loadMissing('games.players');

        foreach ($match->games as $game) {
            foreach ($game->players as $player) {
                if ($player->pivot->is_local) {
                    continue;
                }

                $opponentCards = $opponentCards->merge($player->pivot->deck_json ?? []);
            }
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
            return response()->json([
                'message' => 'No opponent cards found in this match.',
            ], 422);
        }

        return response()->json(ResolveCardsFromDek::run($aggregated));
    }
}
