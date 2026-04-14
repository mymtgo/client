<?php

namespace App\Jobs;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Matches\ExtractGameHandData;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeCardGameStats implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

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
        $gameIds = $games->pluck('id');

        // Clear existing stats so reprocessing works (insertOrIgnore won't update stale rows)
        CardGameStat::whereIn('game_id', $gameIds)->delete();

        $gameLog = GameLog::where('match_token', $match->token)
            ->whereNotNull('decoded_entries')
            ->first();

        $gameLogStats = null;
        if ($gameLog?->decoded_entries) {
            $gameLogStats = ExtractCardsFromGameLog::run($gameLog->decoded_entries);
        }

        $game1Quantities = null;

        foreach ($games as $index => $game) {
            $isPostboard = $index > 0;
            $game1Quantities = $this->processGame($game, $match->deck_version_id, $isPostboard, $game1Quantities, $gameLogStats, $index);
        }
    }

    /**
     * @return array<string, int>|null The oracle_id => quantity map for game 1 (passed forward for sideboard comparison)
     */
    private function processGame(Game $game, int $deckVersionId, bool $isPostboard, ?array $game1Quantities, ?array $gameLogStats, int $gameIndex): ?array
    {
        if ($game->won === null) {
            return null;
        }

        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);

        if (! $localPlayer) {
            return null;
        }

        $localInstanceId = (int) $localPlayer->pivot->instance_id;

        // Get deck cards from game_player pivot (sideboard flags reflect actual per-game state)
        $deckJson = $localPlayer->pivot->deck_json;

        if (empty($deckJson)) {
            return null;
        }

        // Build quantity map from maindeck cards only (sideboard cards aren't in the playing deck)
        $deckCollection = collect($deckJson);
        $deckQuantities = $deckCollection
            ->reject(fn ($card) => $card['sideboard'] ?? false)
            ->mapWithKeys(fn ($card) => [
                (string) $card['mtgo_id'] => (int) $card['quantity'],
            ]);

        // Need all mtgo_ids (including sideboard) for oracle mapping
        $allMtgoIds = $deckCollection->pluck('mtgo_id')->map(fn ($id) => (string) $id)->unique()->values()->toArray();

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
            $keptCatalogIds = $handData['kept_hand'];
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

        $castByOracle = [];
        $playedByOracle = [];
        $kickedByOracle = [];
        $flashbackByOracle = [];
        $madnessByOracle = [];
        $evokedByOracle = [];
        $activatedByOracle = [];

        if ($gameLogStats) {
            $localName = $game->players->first(fn ($p) => $p->pivot->is_local)?->username;
            if ($localName) {
                $gameCards = $gameLogStats['cards_by_game'][$gameIndex][$localName] ?? [];
                foreach ($gameCards as $card) {
                    $oracleId = $catalogToOracle[(string) $card['mtgo_id']] ?? null;
                    if (! $oracleId) {
                        continue;
                    }
                    $castByOracle[$oracleId] = ($castByOracle[$oracleId] ?? 0) + $card['cast'];
                    $playedByOracle[$oracleId] = ($playedByOracle[$oracleId] ?? 0) + $card['played'];
                    $kickedByOracle[$oracleId] = ($kickedByOracle[$oracleId] ?? 0) + $card['kicked'];
                    $flashbackByOracle[$oracleId] = ($flashbackByOracle[$oracleId] ?? 0) + $card['flashback'];
                    $madnessByOracle[$oracleId] = ($madnessByOracle[$oracleId] ?? 0) + $card['madness'];
                    $evokedByOracle[$oracleId] = ($evokedByOracle[$oracleId] ?? 0) + $card['evoked'];
                    $activatedByOracle[$oracleId] = ($activatedByOracle[$oracleId] ?? 0) + $card['activated'];
                }
            }
        }

        // Insert rows
        $rows = [];
        $now = now();

        foreach ($oracleQuantities as $oracleId => $quantity) {
            $sidedOut = false;
            $sidedIn = false;
            if ($isPostboard && $game1Quantities !== null) {
                $g1Qty = $game1Quantities[$oracleId] ?? 0;
                $sidedOut = $quantity < $g1Qty;
                $sidedIn = $quantity > $g1Qty;
            }

            $rows[] = [
                'oracle_id' => $oracleId,
                'game_id' => $game->id,
                'deck_version_id' => $deckVersionId,
                'quantity' => $quantity,
                'kept' => min($keptByOracle[$oracleId] ?? 0, $quantity),
                'seen' => min($seenByOracle[$oracleId] ?? 0, $quantity),
                'cast' => $castByOracle[$oracleId] ?? 0,
                'played' => $playedByOracle[$oracleId] ?? 0,
                'kicked' => $kickedByOracle[$oracleId] ?? 0,
                'flashback' => $flashbackByOracle[$oracleId] ?? 0,
                'madness' => $madnessByOracle[$oracleId] ?? 0,
                'evoked' => $evokedByOracle[$oracleId] ?? 0,
                'activated' => $activatedByOracle[$oracleId] ?? 0,
                'won' => $game->won,
                'is_postboard' => $isPostboard,
                'sided_out' => $sidedOut,
                'sided_in' => $sidedIn,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Cards completely sided out (in game 1 maindeck but entirely absent from current maindeck)
        if ($isPostboard && $game1Quantities !== null) {
            foreach ($game1Quantities as $oracleId => $g1Qty) {
                if ($g1Qty > 0 && ! isset($oracleQuantities[$oracleId])) {
                    $rows[] = [
                        'oracle_id' => $oracleId,
                        'game_id' => $game->id,
                        'deck_version_id' => $deckVersionId,
                        'quantity' => 0,
                        'kept' => 0,
                        'seen' => 0,
                        'cast' => 0,
                        'played' => 0,
                        'kicked' => 0,
                        'flashback' => 0,
                        'madness' => 0,
                        'evoked' => 0,
                        'activated' => 0,
                        'won' => $game->won,
                        'is_postboard' => true,
                        'sided_out' => true,
                        'sided_in' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if (! empty($rows)) {
            CardGameStat::insertOrIgnore($rows);
        }

        if ($gameLogStats) {
            $meta = $gameLogStats['game_meta'][$gameIndex] ?? [];

            if (! empty($meta['turn_count'])) {
                $game->update(['turn_count' => $meta['turn_count']]);
            }

            $localName = $game->players->first(fn ($p) => $p->pivot->is_local)?->username;
            $opponentName = $game->players->first(fn ($p) => ! $p->pivot->is_local)?->username;

            if ($localName && ! empty($meta['dice_rolls'])) {
                DB::table('game_player')
                    ->where('game_id', $game->id)
                    ->where('is_local', true)
                    ->update([
                        'dice_roll' => $meta['dice_rolls'][$localName] ?? null,
                        'mulligan_count' => $meta['mulligans'][$localName] ?? 0,
                    ]);

                DB::table('game_player')
                    ->where('game_id', $game->id)
                    ->where('is_local', false)
                    ->update([
                        'dice_roll' => $meta['dice_rolls'][$opponentName] ?? null,
                        'mulligan_count' => $meta['mulligans'][$opponentName] ?? 0,
                    ]);
            }
        }

        // Return game 1 quantities for sideboard comparison in later games
        return $isPostboard ? $game1Quantities : $oracleQuantities;
    }

    /**
     * Count distinct card instances seen in visible zones per oracle_id.
     *
     * "Seen" = unique card instances that appeared in Hand, Battlefield, Graveyard, Exile, or Stack.
     *
     * @return array<string, int> seenByOracle
     */
    private function computeSeenCards(Game $game, int $localInstanceId, array $catalogToOracle): array
    {
        $seenInstances = []; // [oracle_id => [instanceId => true]]

        foreach ($game->timeline->sortBy('timestamp') as $snapshot) {
            $cards = $snapshot->content['Cards'] ?? [];

            foreach ($cards as $card) {
                if ((int) $card['Owner'] !== $localInstanceId) {
                    continue;
                }

                $zone = $card['Zone'] ?? '';
                $catalogId = (string) ($card['CatalogID'] ?? '');
                $instanceId = (int) ($card['Id'] ?? 0);
                $oracleId = $catalogToOracle[$catalogId] ?? null;

                if (! $oracleId || ! $instanceId) {
                    continue;
                }

                if (in_array($zone, ['Hand', 'Battlefield', 'Graveyard', 'Exile', 'Stack'])) {
                    $seenInstances[$oracleId][$instanceId] = true;
                }
            }
        }

        return array_map('count', $seenInstances);
    }
}
