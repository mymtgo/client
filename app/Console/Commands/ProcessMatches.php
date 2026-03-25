<?php

namespace App\Console\Commands;

use App\Actions\Matches\AdvanceMatchState;
use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\DiscoverGameLogs;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMatches extends Command
{
    protected $signature = 'mtgo:process-matches';

    protected $description = 'Unified pipeline: ingest logs, advance matches, resolve game results';

    /** @var array<string> Tokens processed in the first loop */
    private array $processedTokens = [];

    public function handle(): int
    {
        if (! app('mtgo')->pathsAreValid()) {
            return self::SUCCESS;
        }

        // Phase 0: Discover game logs
        DiscoverGameLogs::run();

        // Phase 1: Ingest main log (ingestLogs handles its own transaction + canRun check)
        app('mtgo')->ingestLogs();

        // Phase 2: Process matches
        $this->processMatchesWithNewEvents();
        $this->checkGameLogsForActiveMatches();

        return self::SUCCESS;
    }

    private function processMatchesWithNewEvents(): void
    {
        $tokensWithWork = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->where('event_type', '!=', 'league_joined')
            ->where('event_type', '!=', 'league_join_request')
            ->distinct()
            ->pluck('match_id', 'match_token');

        foreach ($tokensWithWork as $matchToken => $matchId) {
            $this->processMatch($matchToken, $matchId);
            $this->processedTokens[] = $matchToken;
        }
    }

    private function checkGameLogsForActiveMatches(): void
    {
        $activeMatches = MtgoMatch::whereIn('state', [
            MatchState::InProgress,
            MatchState::Ended,
        ])
            ->whereNull('failed_at')
            ->whereNotIn('token', $this->processedTokens)
            ->get();

        foreach ($activeMatches as $match) {
            try {
                $this->resolveGameResults($match);
            } catch (\Throwable $e) {
                $this->handleMatchFailure($match, $e);
            }
        }
    }

    private function processMatch(string $matchToken, int|string $matchId): void
    {
        $existingMatch = MtgoMatch::where('token', $matchToken)->first();
        if ($existingMatch?->failed_at !== null) {
            return;
        }

        // Username resolution (from BuildMatches pattern)
        $username = LogEvent::where('match_token', $matchToken)
            ->whereNotNull('username')
            ->value('username');

        if (! $username) {
            $this->handleMissingUsername($matchToken);

            return;
        }

        $account = Account::where('username', $username)->first();

        if ($account && ! $account->tracked) {
            $this->markEventsProcessed($matchToken);

            return;
        }

        Mtgo::setUsername($username);

        try {
            DB::transaction(function () use ($matchToken, $matchId) {
                $match = AdvanceMatchState::run($matchToken, $matchId);

                if (! $match) {
                    $this->markStaleEventsProcessed($matchToken);

                    return;
                }

                // Check game log for results inline
                if (in_array($match->state, [MatchState::InProgress, MatchState::Ended])) {
                    $this->resolveGameResults($match);
                }

                // Mark all events for this match token as processed
                $this->markEventsProcessed($matchToken);
            });
        } catch (\Throwable $e) {
            $match = $existingMatch ?? MtgoMatch::where('token', $matchToken)->first();

            if ($match) {
                $this->handleMatchFailure($match, $e);
            } else {
                Log::channel('pipeline')->error("ProcessMatches: exception for token={$matchToken} (no match record)", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveGameResults(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)->first();

        if (! $gameLog) {
            $gameLog = DiscoverGameLogs::discoverForToken($match->token);
        }

        if (! $gameLog || ! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
            return;
        }

        // Parse fresh every tick
        $decoded = ParseGameLogBinary::run($gameLog->file_path);

        if (empty($decoded)) {
            return;
        }

        $players = ExtractGameResults::detectPlayers($decoded);
        $username = Mtgo::resolveUsername($players);

        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($decoded, $username);

        // Sync game results progressively
        $this->syncGameResults($match, $extracted['results'], $extracted['games']);

        // Check if decisive
        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $disconnectDetected = collect($extracted['games'])
            ->contains(fn ($g) => ($g['end_reason'] ?? '') === 'disconnect');

        $result = DetermineMatchResult::run(
            logResults: $extracted['results'],
            stateChanges: $stateChanges,
            matchScoreExists: $extracted['match_decided'],
            disconnectDetected: $disconnectDetected,
        );

        if ($result['decided']) {
            $previousState = $match->state;
            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

            $match->update([
                'outcome' => $outcome,
                'state' => MatchState::Complete,
                'ended_at' => $match->state === MatchState::InProgress
                    ? now()
                    : $match->ended_at,
            ]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete", [
                'result' => "{$result['wins']}-{$result['losses']}",
                'outcome' => $outcome->value,
                'source' => 'game_log',
            ]);
        }
    }

    private function syncGameResults(MtgoMatch $match, array $results, array $gameData): void
    {
        $games = $match->games()->orderBy('started_at')->get();

        foreach ($games as $index => $game) {
            if (! isset($results[$index])) {
                continue;
            }

            $updates = [];

            if ($game->won === null || (bool) $game->won !== $results[$index]) {
                $updates['won'] = $results[$index];
            }

            if ($game->ended_at === null && ! empty($gameData[$index]['ended_at'])) {
                $updates['ended_at'] = $gameData[$index]['ended_at'];
            }

            if (! empty($updates)) {
                $game->update($updates);
            }
        }
    }

    private function handleMatchFailure(MtgoMatch $match, \Throwable $e): void
    {
        $attempts = $match->attempts + 1;
        $updates = ['attempts' => $attempts];

        if ($attempts >= 5) {
            $updates['failed_at'] = now();
            Log::channel('pipeline')->error("Match {$match->mtgo_id}: permanently failed after {$attempts} attempts", [
                'error' => $e->getMessage(),
            ]);
        } else {
            Log::channel('pipeline')->warning("Match {$match->mtgo_id}: attempt {$attempts}/5 failed", [
                'error' => $e->getMessage(),
            ]);
        }

        // Update outside the rolled-back transaction
        $match->update($updates);
    }

    private function markEventsProcessed(string $matchToken): void
    {
        LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    private function handleMissingUsername(string $matchToken): void
    {
        $stale = LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->where('ingested_at', '<', now()->subMinutes(2))
            ->exists();

        if ($stale) {
            $this->markEventsProcessed($matchToken);
            Log::channel('pipeline')->info("ProcessMatches: marked stale events processed for token={$matchToken} (no username after 2 min)");
        }
    }

    private function markStaleEventsProcessed(string $matchToken): void
    {
        $stale = LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->where('ingested_at', '<', now()->subMinutes(2))
            ->exists();

        if ($stale) {
            $this->markEventsProcessed($matchToken);
            Log::channel('pipeline')->info("ProcessMatches: marked stale events processed for token={$matchToken} (no join event after 2 min)");
        }
    }
}
