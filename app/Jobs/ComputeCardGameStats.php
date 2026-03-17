<?php

namespace App\Jobs;

use App\Actions\Matches\ExtractGameHandData;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ComputeCardGameStats implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $matchId,
    ) {}

    public function handle(): void
    {
        $match = MtgoMatch::with('games.players', 'games.timeline')->find($this->matchId);

        if (! $match || ! $match->deck_version_id) {
            return;
        }

        $games = $match->games->sortBy('started_at')->values();
        $game1Quantities = null;

        foreach ($games as $index => $game) {
            $isPostboard = $index > 0;
            $game1Quantities = $this->processGame($game, $match->deck_version_id, $isPostboard, $game1Quantities);
        }
    }

    /**
     * @return array<string, int>|null The oracle_id => quantity map for game 1 (passed forward for sideboard comparison)
     */
    private function processGame(Game $game, int $deckVersionId, bool $isPostboard, ?array $game1Quantities): ?array
    {
        if ($game->won === null) {
            return null;
        }

        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);

        if (! $localPlayer) {
            return null;
        }

        $localInstanceId = (int) $localPlayer->pivot->instance_id;

        // Get deck cards from game_player pivot (has mtgo_ids, reflects sideboard changes)
        $deckJson = $localPlayer->pivot->deck_json;

        if (empty($deckJson)) {
            return null;
        }

        // Build quantity map: mtgo_id => quantity
        $deckQuantities = collect($deckJson)->mapWithKeys(fn ($card) => [
            (string) $card['mtgo_id'] => (int) $card['quantity'],
        ]);

        $allMtgoIds = $deckQuantities->keys()->toArray();

        // Map mtgo_id => oracle_id
        $mtgoToOracle = Card::whereIn('mtgo_id', $allMtgoIds)
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id', 'mtgo_id');

        if ($mtgoToOracle->isEmpty()) {
            return null;
        }

        // Build oracle_id => total quantity in deck
        $oracleQuantities = [];
        foreach ($deckQuantities as $mtgoId => $qty) {
            $oracleId = $mtgoToOracle->get((string) $mtgoId);
            if ($oracleId) {
                $oracleQuantities[$oracleId] = ($oracleQuantities[$oracleId] ?? 0) + $qty;
            }
        }

        // Build reverse map: CatalogID => oracle_id (for timeline lookups)
        $catalogToOracle = $mtgoToOracle->toArray();

        // Get kept hand CatalogIDs
        try {
            $handData = ExtractGameHandData::run($game);
            $keptCatalogIds = $handData['kept_hand'] ?? [];
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning("ComputeCardGameStats: failed to extract hand data for game {$game->id}: {$e->getMessage()}");
            $keptCatalogIds = [];
        }

        // Count kept copies per oracle_id
        $keptByOracle = [];
        foreach ($keptCatalogIds as $catalogId) {
            $oracleId = $catalogToOracle[(string) $catalogId] ?? null;
            if ($oracleId) {
                $keptByOracle[$oracleId] = ($keptByOracle[$oracleId] ?? 0) + 1;
            }
        }

        // Count seen copies per oracle_id from timeline
        $seenByOracle = $this->computeSeenCards($game, $localInstanceId, $catalogToOracle);

        // Insert rows
        $rows = [];
        $now = now();

        foreach ($oracleQuantities as $oracleId => $quantity) {
            $sidedOut = false;
            if ($isPostboard && $game1Quantities !== null) {
                $g1Qty = $game1Quantities[$oracleId] ?? 0;
                $sidedOut = $quantity < $g1Qty;
            }

            $rows[] = [
                'oracle_id' => $oracleId,
                'game_id' => $game->id,
                'deck_version_id' => $deckVersionId,
                'quantity' => $quantity,
                'kept' => min($keptByOracle[$oracleId] ?? 0, $quantity),
                'seen' => min($seenByOracle[$oracleId] ?? 0, $quantity),
                'won' => $game->won,
                'is_postboard' => $isPostboard,
                'sided_out' => $sidedOut,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            CardGameStat::insertOrIgnore($rows);
        }

        // Return game 1 quantities for sideboard comparison in later games
        return $isPostboard ? $game1Quantities : $oracleQuantities;
    }

    /**
     * Count distinct card instances seen in visible zones per oracle_id.
     */
    private function computeSeenCards(Game $game, int $localInstanceId, array $catalogToOracle): array
    {
        $seenInstances = []; // [oracle_id => [instanceId => true]]

        foreach ($game->timeline as $snapshot) {
            $cards = $snapshot->content['Cards'] ?? [];

            foreach ($cards as $card) {
                if ((int) $card['Owner'] !== $localInstanceId) {
                    continue;
                }

                $zone = $card['Zone'] ?? '';
                if (! in_array($zone, ['Hand', 'Battlefield', 'Graveyard', 'Exile', 'Stack'])) {
                    continue;
                }

                $catalogId = (string) ($card['CatalogID'] ?? '');
                $instanceId = (int) ($card['Id'] ?? 0);
                $oracleId = $catalogToOracle[$catalogId] ?? null;

                if ($oracleId && $instanceId) {
                    $seenInstances[$oracleId][$instanceId] = true;
                }
            }
        }

        // Convert to counts
        return array_map('count', $seenInstances);
    }
}
