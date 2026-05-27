<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ExtractMetaMessageEntries;
use App\Actions\Matches\SyncGamePivots;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ResolveMatchFromMetaMessages
{
    /**
     * Resolve a live match using MetaMessage events in the log_events table.
     *
     * Short-circuits unless MTGO has written a "Match State Changed → *CompletedState"
     * line for the match. When the signal arrives, walks the match's MetaMessage
     * events, extracts text entries, and runs the existing ExtractGameResults +
     * DetermineMatchResult chain. Marks the match Complete with outcome + ended_at
     * when the result is decisive.
     */
    public static function run(MtgoMatch $match): void
    {
        if ($match->state === MatchState::Complete) {
            return;
        }

        if (! $match->hasValidPlayers()) {
            return;
        }

        if (! self::hasCompletedSignal($match->token)) {
            return;
        }

        $entries = ExtractMetaMessageEntries::run($match->token);

        if (empty($entries)) {
            return;
        }

        $players = ExtractGameResults::detectPlayers($entries);
        $username = Mtgo::resolveUsername($players);

        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($entries, $username);

        self::syncGames($match, $extracted['games'], $username);

        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $disconnectDetected = collect($extracted['games'])
            ->contains(fn ($g) => ($g['end_reason'] ?? '') === 'disconnect');

        $result = DetermineMatchResult::run(
            games: $extracted['games'],
            localPlayer: $username,
            stateChanges: $stateChanges,
            matchScore: $extracted['match_score'],
            matchScoreExists: $extracted['match_decided'],
            disconnectDetected: $disconnectDetected,
        );

        // The CompletedState signal we gated on at the top is itself authoritative
        // proof the match is over — DetermineMatchResult only recognises a narrower
        // set of state-change variants in $event->context (MatchCompletedState /
        // MatchClosedState / ConcedeReqState→NotJoined) and won't return decided=true
        // for league variants like LeagueMatchJoinedCompletedState. Since we've
        // already short-circuited on that signal being present, we trust it and
        // proceed with whatever wins/losses we counted.

        $previousState = $match->state;
        $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);
        $lastTs = end($entries)['timestamp'] ?? null;
        $endedAt = $lastTs ? Carbon::parse($lastTs) : now();

        $match->update([
            'outcome' => $outcome,
            'state' => MatchState::Complete,
            'games_won' => $result['wins'],
            'games_lost' => $result['losses'],
            'ended_at' => $endedAt,
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete", [
            'result' => "{$result['wins']}-{$result['losses']}",
            'outcome' => $outcome->value,
            'source' => 'metamessage',
        ]);
    }

    private static function hasCompletedSignal(string $matchToken): bool
    {
        return LogEvent::query()
            ->where('match_token', $matchToken)
            ->where('event_type', 'match_state_changed')
            ->where(function ($q) {
                $q->where('raw_text', 'like', '%LeagueMatchJoinedCompletedState%')
                    ->orWhere('raw_text', 'like', '%MatchJoinedCompletedState%')
                    ->orWhere('raw_text', 'like', '%MatchClosedState%');
            })
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $games
     */
    private static function syncGames(MtgoMatch $match, array $games, string $username): void
    {
        $persistedGames = $match->games()->with('players')->orderBy('started_at')->get();

        foreach ($persistedGames as $index => $game) {
            SyncGamePivots::forGame($game, $games[$index] ?? null, $username);
        }
    }
}
