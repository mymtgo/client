<?php

namespace App\Actions\Import;

use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\DeckVersion;
use App\Models\Game;

class ComputeImportedCardGameStats
{
    /**
     * Create reduced-fidelity card_game_stats for an imported game.
     *
     * @param  array<int>  $seenMtgoIds  CatalogIDs seen in game log for local player
     */
    public static function run(Game $game, int $deckVersionId, array $seenMtgoIds, bool $isPostboard): void
    {
        if ($game->won === null) {
            return;
        }

        $version = DeckVersion::find($deckVersionId);

        if (! $version) {
            return;
        }

        $deckCards = collect($version->cards);

        if ($deckCards->isEmpty()) {
            return;
        }

        // Map seen mtgo_ids to oracle_ids
        $seenOracleIds = [];
        if (! empty($seenMtgoIds)) {
            $seenOracleIds = Card::whereIn('mtgo_id', $seenMtgoIds)
                ->whereNotNull('oracle_id')
                ->pluck('oracle_id')
                ->unique()
                ->toArray();
        }

        // Build mainboard quantities by oracle_id
        $mainboardQuantities = [];
        foreach ($deckCards as $card) {
            if (($card['sideboard'] ?? 'false') === 'false') {
                $oracleId = $card['oracle_id'];
                $mainboardQuantities[$oracleId] = ($mainboardQuantities[$oracleId] ?? 0) + (int) $card['quantity'];
            }
        }

        $rows = [];
        $now = now();

        foreach ($mainboardQuantities as $oracleId => $quantity) {
            $rows[] = [
                'oracle_id' => $oracleId,
                'game_id' => $game->id,
                'deck_version_id' => $deckVersionId,
                'quantity' => $quantity,
                'kept' => 0,
                'seen' => in_array($oracleId, $seenOracleIds) ? 1 : 0,
                'won' => $game->won,
                'is_postboard' => $isPostboard,
                'sided_out' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            CardGameStat::insertOrIgnore($rows);
        }
    }
}
