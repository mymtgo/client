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
     * @param  array<int, array{mtgo_id: int, cast: int}>  $cardStats  Cards seen in game log with cast counts
     */
    public static function run(Game $game, int $deckVersionId, array $cardStats, bool $isPostboard): void
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

        // Extract mtgo_ids and cast counts from card stats
        $seenMtgoIds = [];
        $castByMtgoId = [];
        foreach ($cardStats as $stat) {
            $seenMtgoIds[] = $stat['mtgo_id'];
            $castByMtgoId[$stat['mtgo_id']] = $stat['cast'];
        }

        // Map seen mtgo_ids to oracle_ids and build cast-by-oracle lookup
        $seenOracleIds = [];
        $castByOracle = [];
        if (! empty($seenMtgoIds)) {
            $mtgoToOracle = Card::whereIn('mtgo_id', $seenMtgoIds)
                ->whereNotNull('oracle_id')
                ->pluck('oracle_id', 'mtgo_id');

            $seenOracleIds = $mtgoToOracle->unique()->values()->toArray();

            foreach ($mtgoToOracle as $mtgoId => $oracleId) {
                $castByOracle[$oracleId] = ($castByOracle[$oracleId] ?? 0) + ($castByMtgoId[$mtgoId] ?? 0);
            }
        }

        // Build mainboard quantities by oracle_id
        $mainboardQuantities = [];
        foreach ($deckCards as $card) {
            if (strtolower((string) ($card['sideboard'] ?? 'false')) !== 'true') {
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
                'cast' => min($castByOracle[$oracleId] ?? 0, $quantity),
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
