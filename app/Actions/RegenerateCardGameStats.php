<?php

namespace App\Actions;

use App\Actions\Import\ComputeImportedCardGameStats;
use App\Actions\Import\ExtractCardsFromGameLog;
use App\Jobs\ComputeCardGameStats;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RegenerateCardGameStats
{
    /**
     * Recompute card_game_stats for all complete matches.
     *
     * Per-match delete-then-insert is handled inside each match's processing path
     * (live: ComputeCardGameStats::handle; imported: reprocessImportedMatch below),
     * so the table is never globally empty during regeneration.
     *
     * @return array{live: int, imported: int}
     */
    public static function run(): array
    {
        return self::regenerate();
    }

    /**
     * Recompute card_game_stats for every complete match tied to this deck.
     *
     * @return array{live: int, imported: int}
     */
    public static function forDeck(Deck $deck): array
    {
        $deckVersionIds = $deck->versions()->pluck('id')->all();

        if (empty($deckVersionIds)) {
            return ['live' => 0, 'imported' => 0];
        }

        $result = self::regenerate(fn (Builder $query) => $query->whereIn('deck_version_id', $deckVersionIds));

        Log::info("RegenerateCardGameStats: scoped to deck {$deck->id} — queued {$result['live']} live, processed {$result['imported']} imported");

        return $result;
    }

    /**
     * @param  callable(Builder): Builder|null  $scope
     * @return array{live: int, imported: int}
     */
    private static function regenerate(?callable $scope = null): array
    {
        $live = 0;
        $imported = 0;

        $liveQuery = MtgoMatch::query()
            ->where('imported', false)
            ->where('state', 'complete')
            ->whereNotNull('deck_version_id')
            ->whereHas('games');

        if ($scope) {
            $scope($liveQuery);
        }

        foreach ($liveQuery->pluck('id') as $matchId) {
            ComputeCardGameStats::dispatch($matchId)->onQueue('default');
            $live++;
        }

        $importedQuery = MtgoMatch::query()
            ->where('imported', true)
            ->whereNotNull('deck_version_id')
            ->whereHas('games')
            ->with(['games.players']);

        if ($scope) {
            $scope($importedQuery);
        }

        foreach ($importedQuery->get() as $match) {
            try {
                self::reprocessImportedMatch($match);
                $imported++;
            } catch (\Throwable $e) {
                Log::warning("RegenerateCardGameStats: failed imported match {$match->id}: {$e->getMessage()}");
            }
        }

        return ['live' => $live, 'imported' => $imported];
    }

    private static function reprocessImportedMatch(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)
            ->whereNotNull('decoded_entries')
            ->first();

        if (! $gameLog) {
            return;
        }

        // Clear this match's existing rows so ComputeImportedCardGameStats' insertOrIgnore
        // doesn't silently skip updates — unique (oracle_id, game_id) would otherwise retain stale rows.
        CardGameStat::whereIn('game_id', $match->games->pluck('id'))->delete();

        $cardData = ExtractCardsFromGameLog::run($gameLog->decoded_entries);
        $cardsByGame = $cardData['cards_by_game'] ?? [];
        $gameMeta = $cardData['game_meta'] ?? [];
        $pregameActions = $cardData['pregame_actions'] ?? [];

        $firstGame = $match->games->sortBy('started_at')->first();
        if (! $firstGame) {
            return;
        }

        $localPlayer = $firstGame->players->first(fn ($p) => $p->pivot->is_local);
        if (! $localPlayer) {
            return;
        }

        $localName = $localPlayer->username;

        foreach ($match->games->sortBy('started_at')->values() as $index => $game) {
            if ($game->won === null || ! $match->deck_version_id) {
                continue;
            }

            $gameCards = $cardsByGame[$index][$localName] ?? [];

            ComputeImportedCardGameStats::run(
                $game,
                $match->deck_version_id,
                $gameCards,
                isPostboard: $index > 0,
                pregameActions: $pregameActions[$index][$localName] ?? [],
            );

            // Write game metadata
            $meta = $gameMeta[$index] ?? [];
            if (! empty($meta['turn_count'])) {
                $game->update(['turn_count' => $meta['turn_count']]);
            }
        }
    }
}
