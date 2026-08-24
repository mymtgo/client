<?php

namespace App\Actions\Matches;

use App\Actions\Overlay\SyncGameOverlayVisibility;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AbandonStaleMatches
{
    /**
     * Give a terminal state to in_progress matches that have gone quiet.
     *
     * MTGO writes no closing state when the client is killed mid-match (e.g.
     * quitting during sideboarding), leaving the match stuck in_progress
     * forever. Once a match carries no match-end signal AND has seen no new
     * log activity within the configured window, it is resolved post-mortem:
     *   - if the final logged action was a disconnect, the surviving player
     *     wins the match (a no-return disconnect is a forfeit);
     *   - otherwise there is nothing to decide it on, so it is Abandoned.
     *
     * Matches that DO carry an end signal are left untouched — those are
     * resolvable by reprocessing in ProcessMatchEvents.
     */
    public static function run(): void
    {
        $cutoff = now()->subMinutes((int) config('mtgo.match_abandon_after_minutes', 60));

        $resolvedAny = MtgoMatch::query()
            ->where('state', MatchState::InProgress)
            ->whereNull('failed_at')
            ->get()
            ->map(fn (MtgoMatch $match) => self::evaluate($match, $cutoff))
            ->contains(true);

        if ($resolvedAny) {
            SyncGameOverlayVisibility::run();
        }
    }

    /**
     * Returns true when the match was given a terminal state.
     */
    private static function evaluate(MtgoMatch $match, Carbon $cutoff): bool
    {
        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        if (self::hasEndSignal($stateChanges)) {
            return false;
        }

        $lastActivity = self::lastActivityAt($match);

        // Still active (or active recently) — a disconnect here may still be
        // followed by a reconnect, so we must not decide anything yet.
        if ($lastActivity === null || $lastActivity->greaterThan($cutoff)) {
            return false;
        }

        // The match has conclusively gone quiet. If its last logged action was
        // a disconnect, the survivor wins; otherwise there is nothing to
        // resolve it on and it is abandoned.
        if (self::resolveByDisconnect($match)) {
            self::stopRediscovery($match);

            return true;
        }

        $match->update([
            'state' => MatchState::Abandoned,
            'ended_at' => $lastActivity,
        ]);

        self::stopRediscovery($match);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: marked Abandoned (no end signal, inactive since {$lastActivity->toDateTimeString()})");

        return true;
    }

    /**
     * Resolve a quiet match whose final logged action was a disconnect: the
     * player who did NOT disconnect wins the match. Returns false (leaving the
     * caller to abandon) when there is no terminal disconnect or the local
     * player cannot be identified.
     */
    private static function resolveByDisconnect(MtgoMatch $match): bool
    {
        $entries = ExtractMetaMessageEntries::run($match->token);

        $disconnect = self::lastActionDisconnect($entries);

        if ($disconnect === null) {
            return false;
        }

        $players = ExtractGameResults::detectPlayers($entries);
        $local = Mtgo::resolveUsername($players);

        if ($local === null || ! in_array($local, $players, true)) {
            return false;
        }

        $survivor = self::otherPlayer($disconnect['player'], $players);

        if ($survivor === null) {
            return false;
        }

        $outcome = $survivor === $local ? MatchOutcome::Win : MatchOutcome::Loss;
        $endedAt = $disconnect['timestamp'] ? Carbon::parse($disconnect['timestamp']) : now();

        $match->update([
            'state' => MatchState::Complete,
            'outcome' => $outcome,
            'ended_at' => $endedAt,
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: resolved by disconnect — {$disconnect['player']} dropped, {$survivor} wins ({$outcome->value})");

        return true;
    }

    /**
     * Return the player who disconnected as the match's final action, or null
     * when the last terminal action was something else (a later win, concede,
     * or match-win line means the disconnect was not the deciding event).
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @return array{player: string, timestamp: ?string}|null
     */
    private static function lastActionDisconnect(array $entries): ?array
    {
        $pattern = ExtractGameResults::PLAYER_PATTERN;
        $disconnect = null;

        foreach ($entries as $entry) {
            $message = $entry['message'];

            if (preg_match('/^@P('.$pattern.') has lost connection to the game/', $message, $m)) {
                $disconnect = ['player' => $m[1], 'timestamp' => $entry['timestamp'] ?? null];
            } elseif (preg_match('/wins the game|has conceded from the game|wins the match/', $message)) {
                $disconnect = null;
            }
        }

        return $disconnect;
    }

    /**
     * @param  array<int, string>  $players
     */
    private static function otherPlayer(string $player, array $players): ?string
    {
        foreach ($players as $candidate) {
            if ($candidate !== $player) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Stop the match's trailing events from being rediscovered each tick.
     */
    private static function stopRediscovery(MtgoMatch $match): void
    {
        LogEvent::where('match_token', $match->token)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    /**
     * Most recent log activity for the match. match_state_changed and
     * game_management_json carry match_token; game_state_update carries only
     * match_id — so both columns must be considered.
     */
    private static function lastActivityAt(MtgoMatch $match): ?Carbon
    {
        $latest = LogEvent::query()
            ->where('match_token', $match->token)
            ->orWhere('match_id', $match->mtgo_id)
            ->max('logged_at');

        return $latest ? Carbon::parse($latest) : null;
    }

    /**
     * Mirrors AdvanceMatchState::tryAdvanceToEnded — if any of these signals is
     * present the match can still advance through the normal pipeline.
     *
     * @param  Collection<int, LogEvent>  $stateChanges
     */
    private static function hasEndSignal(Collection $stateChanges): bool
    {
        $serverEnded = $stateChanges->contains(
            fn (LogEvent $event) => Str::contains($event->context ?? '', [
                'TournamentMatchClosedState',
                'MatchCompletedState',
                'MatchEndedState',
                'MatchClosedState',
                'JoinedCompletedState',
            ])
        );

        return $serverEnded || DetermineMatchResult::localPlayerConceded($stateChanges);
    }
}
