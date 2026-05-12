<?php

namespace App\Jobs;

use App\Actions\Cards\AggregateGameLogCardStats;
use App\Actions\Cards\CountSeenCardsByOracle;
use App\Actions\Cards\UpdateGameMetaFromLog;
use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Matches\ExtractGameHandData;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ComputeCardGameStats implements ShouldQueue
{
    use Queueable;

    private const LOCAL_VISIBLE_ZONES = ['Hand', 'Battlefield', 'Graveyard', 'Exile', 'Stack'];

    private const OPPONENT_VISIBLE_ZONES = ['Battlefield', 'Graveyard', 'Exile', 'Stack'];

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

        // Delete-then-insert: re-runs stay idempotent. Without this, insertOrIgnore
        // leaves stale rows for cards no longer in the deck or no longer signaled by opp.
        CardGameStat::whereIn('game_id', $games->pluck('id'))->delete();

        $gameLog = GameLog::where('match_token', $match->token)
            ->whereNotNull('decoded_entries')
            ->first();

        $gameLogStats = $gameLog?->decoded_entries
            ? ExtractCardsFromGameLog::run($gameLog->decoded_entries)
            : null;

        $sideboardOracleIds = $this->resolveSideboardOracleIds($match->deck_version_id);
        $imported = (bool) $match->imported;

        // Imported matches build deck_json from cards "seen" in the game log only,
        // so per-game quantities under-report the real maindeck. Comparing those
        // against each other produces false sided_in/sided_out signals for any
        // card that simply wasn't drawn in g1 — keep g1Quantities null so the
        // comparison short-circuits.
        $trackSideboarding = ! $imported;

        $game1Quantities = null;

        foreach ($games as $index => $game) {
            $isPostboard = $index > 0;
            $next = $this->processGame($game, $match->deck_version_id, $isPostboard, $game1Quantities, $gameLogStats, $index, $sideboardOracleIds, $imported);

            if (! $isPostboard && $trackSideboarding) {
                $game1Quantities = $next;
            }
        }
    }

    /**
     * Oracle ids of cards present in the deck version's sideboard. Used to emit
     * zero-quantity postboard rows for sideboard cards that stayed in the
     * sideboard, so SB In % has the correct postboard-game denominator.
     *
     * @return array<int, string>
     */
    private function resolveSideboardOracleIds(int $deckVersionId): array
    {
        $version = DeckVersion::find($deckVersionId);

        if (! $version) {
            return [];
        }

        return collect($version->cards)
            ->filter(fn ($card) => $this->isSideboardCard($card) && ! empty($card['oracle_id']))
            ->pluck('oracle_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * @param  array<string, mixed>|null  $gameLogStats
     * @param  array<string, int>|null  $game1Quantities
     * @param  array<int, string>  $sideboardOracleIds
     * @return array<string, int>|null oracle_id => quantity for game 1 (forwarded for sideboard comparison)
     */
    private function processGame(Game $game, int $deckVersionId, bool $isPostboard, ?array $game1Quantities, ?array $gameLogStats, int $gameIndex, array $sideboardOracleIds, bool $imported): ?array
    {
        if ($game->won === null) {
            return null;
        }

        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);

        if (! $localPlayer) {
            return null;
        }

        $nextGame1Quantities = $this->processLocalSide($game, $localPlayer, $deckVersionId, $isPostboard, $game1Quantities, $gameLogStats, $gameIndex, $sideboardOracleIds, $imported);

        $this->processOpponentSide($game, $deckVersionId, $isPostboard, $gameLogStats, $gameIndex);

        if ($gameLogStats) {
            UpdateGameMetaFromLog::run($game, $gameLogStats, $gameIndex);
        }

        return $isPostboard ? $game1Quantities : $nextGame1Quantities;
    }

    /**
     * @param  array<string, int>|null  $game1Quantities
     * @param  array<string, mixed>|null  $gameLogStats
     * @param  array<int, string>  $sideboardOracleIds
     * @return array<string, int>|null oracle_id => maindeck quantity (game-1 only)
     */
    private function processLocalSide(Game $game, $localPlayer, int $deckVersionId, bool $isPostboard, ?array $game1Quantities, ?array $gameLogStats, int $gameIndex, array $sideboardOracleIds, bool $imported): ?array
    {
        $localInstanceId = (int) $localPlayer->pivot->instance_id;
        $deckJson = $this->resolveDeckJson($localPlayer, $deckVersionId, $imported);

        if (empty($deckJson)) {
            return null;
        }

        $deckCollection = collect($deckJson);
        $deckQuantities = $deckCollection
            ->reject(fn ($card) => $this->isSideboardCard($card))
            ->mapWithKeys(fn ($card) => [
                (string) $card['mtgo_id'] => (int) $card['quantity'],
            ]);

        $allMtgoIds = $deckCollection->pluck('mtgo_id')->map(fn ($id) => (string) $id)->unique()->values()->toArray();

        $mtgoToOracle = Card::whereIn('mtgo_id', $allMtgoIds)
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id', 'mtgo_id');

        if ($mtgoToOracle->isEmpty()) {
            return null;
        }

        $oracleQuantities = [];
        foreach ($deckQuantities as $mtgoId => $qty) {
            $oracleId = $mtgoToOracle->get((string) $mtgoId);
            if ($oracleId) {
                $oracleQuantities[$oracleId] = ($oracleQuantities[$oracleId] ?? 0) + $qty;
            }
        }

        $catalogToOracle = $mtgoToOracle->toArray();

        try {
            $handData = ExtractGameHandData::run($game);
            $keptCatalogIds = $handData['kept_hand'];
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning("ComputeCardGameStats: failed to extract hand data for game {$game->id}: {$e->getMessage()}");
            $keptCatalogIds = [];
        }

        $keptByOracle = [];
        foreach ($keptCatalogIds as $catalogId) {
            $oracleId = $catalogToOracle[(string) $catalogId] ?? null;
            if ($oracleId) {
                $keptByOracle[$oracleId] = ($keptByOracle[$oracleId] ?? 0) + 1;
            }
        }

        $logStats = $gameLogStats
            ? AggregateGameLogCardStats::run($gameLogStats, $gameIndex, $localPlayer->username, $catalogToOracle)
            : $this->emptyLogStats();

        $seenByOracle = $this->resolveSeenByOracle($game, $localInstanceId, $catalogToOracle, self::LOCAL_VISIBLE_ZONES, $logStats);

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

            $rows[] = $this->buildRow(
                oracleId: $oracleId,
                gameId: $game->id,
                deckVersionId: $deckVersionId,
                quantity: $quantity,
                kept: min($keptByOracle[$oracleId] ?? 0, $quantity),
                seen: min($seenByOracle[$oracleId] ?? 0, $quantity),
                logStats: $logStats,
                won: (bool) $game->won,
                isPostboard: $isPostboard,
                sidedOut: $sidedOut,
                sidedIn: $sidedIn,
                opponent: false,
                now: $now,
            );
        }

        if ($isPostboard && $game1Quantities !== null) {
            foreach ($game1Quantities as $oracleId => $g1Qty) {
                if ($g1Qty > 0 && ! isset($oracleQuantities[$oracleId])) {
                    $rows[] = $this->buildRow(
                        oracleId: $oracleId,
                        gameId: $game->id,
                        deckVersionId: $deckVersionId,
                        quantity: 0,
                        kept: 0,
                        seen: 0,
                        logStats: $this->emptyLogStats(),
                        won: (bool) $game->won,
                        isPostboard: true,
                        sidedOut: true,
                        sidedIn: false,
                        opponent: false,
                        now: $now,
                    );
                }
            }
        }

        // Sideboard cards that stayed in sideboard get a zero-quantity postboard
        // row so SB In % has the correct denominator (every postboard game, not
        // only the ones where the card was sided in).
        if ($isPostboard) {
            foreach ($sideboardOracleIds as $oracleId) {
                if (isset($oracleQuantities[$oracleId])) {
                    continue;
                }
                if (isset($game1Quantities[$oracleId]) && $game1Quantities[$oracleId] > 0) {
                    continue;
                }

                $rows[] = $this->buildRow(
                    oracleId: $oracleId,
                    gameId: $game->id,
                    deckVersionId: $deckVersionId,
                    quantity: 0,
                    kept: 0,
                    seen: 0,
                    logStats: $this->emptyLogStats(),
                    won: (bool) $game->won,
                    isPostboard: true,
                    sidedOut: false,
                    sidedIn: false,
                    opponent: false,
                    now: $now,
                );
            }
        }

        if (! empty($rows)) {
            CardGameStat::insertOrIgnore($rows);
        }

        return $oracleQuantities;
    }

    /**
     * @param  array<string, mixed>|null  $gameLogStats
     */
    private function processOpponentSide(Game $game, int $deckVersionId, bool $isPostboard, ?array $gameLogStats, int $gameIndex): void
    {
        $opponents = $game->players->filter(fn ($p) => ! $p->pivot->is_local);

        if ($opponents->isEmpty()) {
            return;
        }

        $rows = [];
        $now = now();

        foreach ($opponents as $opponent) {
            $oppInstanceId = (int) $opponent->pivot->instance_id;
            $oppName = $opponent->username;

            $catalogToOracle = $this->buildOpponentCatalogToOracle($game, $oppInstanceId, $gameLogStats, $gameIndex, $oppName);

            if (empty($catalogToOracle)) {
                continue;
            }

            $logStats = $gameLogStats
                ? AggregateGameLogCardStats::run($gameLogStats, $gameIndex, $oppName, $catalogToOracle)
                : $this->emptyLogStats();

            $seenByOracle = $this->resolveSeenByOracle($game, $oppInstanceId, $catalogToOracle, self::OPPONENT_VISIBLE_ZONES, $logStats);

            $oracleIds = array_unique(array_values($catalogToOracle));

            foreach ($oracleIds as $oracleId) {
                $seen = $seenByOracle[$oracleId] ?? 0;
                $cast = $logStats['cast'][$oracleId] ?? 0;
                $played = $logStats['played'][$oracleId] ?? 0;
                $activated = $logStats['activated'][$oracleId] ?? 0;
                $kicked = $logStats['kicked'][$oracleId] ?? 0;
                $flashback = $logStats['flashback'][$oracleId] ?? 0;
                $madness = $logStats['madness'][$oracleId] ?? 0;
                $evoked = $logStats['evoked'][$oracleId] ?? 0;
                $pregameRevealed = isset($logStats['pregame_revealed'][$oracleId]);
                $pregamePlayed = isset($logStats['pregame_played'][$oracleId]);

                $hasSignal = $seen > 0
                    || $cast > 0
                    || $played > 0
                    || $activated > 0
                    || $kicked > 0
                    || $flashback > 0
                    || $madness > 0
                    || $evoked > 0
                    || $pregameRevealed
                    || $pregamePlayed;

                if (! $hasSignal) {
                    continue;
                }

                $rows[] = $this->buildRow(
                    oracleId: $oracleId,
                    gameId: $game->id,
                    deckVersionId: $deckVersionId,
                    quantity: 0,
                    kept: 0,
                    seen: $seen,
                    logStats: $logStats,
                    won: (bool) $game->won,
                    isPostboard: $isPostboard,
                    sidedOut: false,
                    sidedIn: false,
                    opponent: true,
                    now: $now,
                );
            }
        }

        if (! empty($rows)) {
            CardGameStat::insertOrIgnore($rows);
        }
    }

    /**
     * Live games: pivot deck_json (captured at match start) is the truth.
     * Imported games: pivot is sparse (only cards "seen" in the log), so the
     * deck-version snapshot is the truth — pivot would deflate denominators
     * to "games where this card appeared" rather than "all games played".
     *
     * @return list<array<string, mixed>>
     */
    private function resolveDeckJson($player, int $deckVersionId, bool $imported): array
    {
        $versionDeck = $this->resolveVersionDeck($deckVersionId);

        if ($imported && ! empty($versionDeck)) {
            return $versionDeck;
        }

        $pivotDeck = $player->pivot->deck_json;
        if (! empty($pivotDeck)) {
            return $pivotDeck;
        }

        return $versionDeck;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveVersionDeck(int $deckVersionId): array
    {
        $version = DeckVersion::find($deckVersionId);
        if (! $version) {
            return [];
        }

        // Old signature format omits mtgo_id; can't resolve to timeline catalog ids.
        return collect($version->cards)
            ->filter(fn ($card) => isset($card['mtgo_id']))
            ->values()
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function isSideboardCard(array $card): bool
    {
        $value = $card['sideboard'] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        return strtolower((string) $value) === 'true';
    }

    /**
     * Counts unique card instances seen via timeline. When timeline lacks data
     * (imported matches), any oracle with a game-log signal becomes seen=1
     * since "appeared in the log" is the strongest available evidence.
     *
     * @param  array<string, string>  $catalogToOracle
     * @param  list<string>  $visibleZones
     * @param  array<string, mixed>  $logStats
     * @return array<string, int>
     */
    private function resolveSeenByOracle(Game $game, int $instanceId, array $catalogToOracle, array $visibleZones, array $logStats): array
    {
        $seenByOracle = CountSeenCardsByOracle::run($game, $instanceId, $catalogToOracle, $visibleZones);

        $signalKeys = ['cast', 'played', 'kicked', 'flashback', 'madness', 'evoked', 'activated'];
        foreach ($signalKeys as $key) {
            foreach ($logStats[$key] ?? [] as $oracleId => $count) {
                if ($count > 0 && ($seenByOracle[$oracleId] ?? 0) === 0) {
                    $seenByOracle[$oracleId] = 1;
                }
            }
        }

        foreach (['pregame_revealed', 'pregame_played'] as $key) {
            foreach ($logStats[$key] ?? [] as $oracleId => $_flag) {
                if (($seenByOracle[$oracleId] ?? 0) === 0) {
                    $seenByOracle[$oracleId] = 1;
                }
            }
        }

        return $seenByOracle;
    }

    /**
     * Catalog-id => oracle-id from cards we observed for this opponent.
     * Source union: (a) timeline cards owned by the opp instance, and (b) mtgo_ids
     * the opp interacted with in the game log. Imported matches have no timeline,
     * so the game-log fallback is the only path that surfaces opp cards there.
     *
     * @param  array<string, mixed>|null  $gameLogStats
     * @return array<string, string>
     */
    private function buildOpponentCatalogToOracle(Game $game, int $oppInstanceId, ?array $gameLogStats, int $gameIndex, string $oppName): array
    {
        $catalogIds = [];

        foreach ($game->timeline as $snapshot) {
            $cards = $snapshot->content['Cards'] ?? [];

            foreach ($cards as $card) {
                if ((int) ($card['Owner'] ?? -1) !== $oppInstanceId) {
                    continue;
                }
                $catalogId = (string) ($card['CatalogID'] ?? '');
                if ($catalogId === '') {
                    continue;
                }
                $catalogIds[$catalogId] = true;
            }
        }

        if ($gameLogStats) {
            foreach ($gameLogStats['cards_by_game'][$gameIndex][$oppName] ?? [] as $card) {
                $mtgoId = (string) ($card['mtgo_id'] ?? '');
                if ($mtgoId !== '') {
                    $catalogIds[$mtgoId] = true;
                }
            }

            foreach ($gameLogStats['pregame_actions'][$gameIndex][$oppName] ?? [] as $action) {
                $mtgoId = (string) ($action['mtgo_id'] ?? '');
                if ($mtgoId !== '') {
                    $catalogIds[$mtgoId] = true;
                }
            }
        }

        if (empty($catalogIds)) {
            return [];
        }

        return Card::whereIn('mtgo_id', array_keys($catalogIds))
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id', 'mtgo_id')
            ->mapWithKeys(fn ($oracleId, $mtgoId) => [(string) $mtgoId => $oracleId])
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $logStats
     * @return array<string, mixed>
     */
    private function buildRow(
        string $oracleId,
        int $gameId,
        int $deckVersionId,
        int $quantity,
        int $kept,
        int $seen,
        array $logStats,
        bool $won,
        bool $isPostboard,
        bool $sidedOut,
        bool $sidedIn,
        bool $opponent,
        $now,
    ): array {
        return [
            'oracle_id' => $oracleId,
            'game_id' => $gameId,
            'deck_version_id' => $deckVersionId,
            'quantity' => $quantity,
            'kept' => $kept,
            'seen' => $seen,
            'cast' => $logStats['cast'][$oracleId] ?? 0,
            'played' => $logStats['played'][$oracleId] ?? 0,
            'kicked' => $logStats['kicked'][$oracleId] ?? 0,
            'flashback' => $logStats['flashback'][$oracleId] ?? 0,
            'madness' => $logStats['madness'][$oracleId] ?? 0,
            'evoked' => $logStats['evoked'][$oracleId] ?? 0,
            'activated' => $logStats['activated'][$oracleId] ?? 0,
            'pregame_revealed' => isset($logStats['pregame_revealed'][$oracleId]),
            'pregame_played' => isset($logStats['pregame_played'][$oracleId]),
            'won' => $won,
            'is_postboard' => $isPostboard,
            'sided_out' => $sidedOut,
            'sided_in' => $sidedIn,
            'opponent' => $opponent,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array{cast: array<string, int>, played: array<string, int>, kicked: array<string, int>, flashback: array<string, int>, madness: array<string, int>, evoked: array<string, int>, activated: array<string, int>, pregame_revealed: array<string, true>, pregame_played: array<string, true>}
     */
    private function emptyLogStats(): array
    {
        return [
            'cast' => [],
            'played' => [],
            'kicked' => [],
            'flashback' => [],
            'madness' => [],
            'evoked' => [],
            'activated' => [],
            'pregame_revealed' => [],
            'pregame_played' => [],
        ];
    }
}
