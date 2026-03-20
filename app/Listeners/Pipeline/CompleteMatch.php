<?php

namespace App\Listeners\Pipeline;

use App\Actions\DetermineMatchArchetypes;
use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\GetGameLog;
use App\Actions\Matches\SyncGameResults;
use App\Actions\Pipeline\ArchiveGameLog;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Events\MatchEnded;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\SubmitMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CompleteMatch
{
    public function handle(MatchEnded $event): void
    {
        $logEvent = $event->logEvent;
        $match = MtgoMatch::findByEvent($logEvent);

        if (! $match) {
            return;
        }

        // Step 1: Transition InProgress → Ended (previously EndMatch listener)
        if ($match->state === MatchState::InProgress) {
            $lastEvent = LogEvent::where('match_token', $match->token)
                ->orderBy('id', 'desc')
                ->first();

            $ended = Carbon::parse($lastEvent->logged_at ?? now())
                ->setTimeFromTimeString($lastEvent->timestamp ?? now()->toTimeString());

            $match->update([
                'ended_at' => $ended,
                'state' => MatchState::Ended,
            ]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: InProgress → Ended", [
                'signal' => $logEvent->context,
            ]);
        }

        // Step 2: Transition Ended → Complete (previously the only logic in this listener)
        if ($match->state !== MatchState::Ended) {
            return;
        }

        $gameLog = GetGameLog::run($match->token);

        // Get join event for metadata extraction
        $joinEvent = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->where('context', 'like', '%MatchJoinedEventUnderwayState%')
            ->first();

        $gameMeta = $joinEvent ? ExtractKeyValueBlock::run($joinEvent->raw_text) : [];

        $maxGames = str_contains($gameMeta['GameStructureCd'] ?? '', 'BO5') ? 5 : 3;
        $logResults = array_slice($gameLog['results'] ?? [], 0, $maxGames);

        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $result = DetermineMatchResult::run($logResults, $stateChanges, $gameMeta['GameStructureCd'] ?? '');

        $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

        $match->update([
            'outcome' => $outcome,
            'state' => MatchState::Complete,
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → Complete", [
            'result' => "{$result['wins']}-{$result['losses']}",
        ]);

        // Update league state if applicable
        if (
            ($league = $match->league)
            && $league->state === LeagueState::Active
            && $league->matches()->where('state', MatchState::Complete)->count() >= 5
        ) {
            $league->update(['state' => LeagueState::Complete]);
        }

        SyncGameResults::run($match, $gameLog['results'] ?? []);

        // Archive the game log before post-completion steps
        try {
            ArchiveGameLog::run($match);
        } catch (\Throwable $e) {
            Log::warning("Failed to archive game log for match {$match->id}: {$e->getMessage()}");
        }

        // Post-completion: each independent, non-critical
        try {
            DetermineMatchArchetypes::run($match);
        } catch (\Throwable $e) {
            Log::warning("Failed to determine archetypes for match {$match->id}: {$e->getMessage()}");
        }

        SubmitMatch::dispatch($match->id);
        ComputeCardGameStats::dispatch($match->id);

        $won = $outcome === MatchOutcome::Win;
        $opponentArchetype = $match->opponentArchetypes()->with('archetype')->first()?->archetype?->name ?? 'Unknown';

        AppNotification::dispatch(
            type: $won ? 'match_win' : 'match_loss',
            title: ($won ? 'Win' : 'Loss').' vs '.$opponentArchetype,
            message: $result['wins'].'-'.$result['losses'],
            route: '/matches/'.$match->id,
        );

        // Clear progressive archetype detection cache
        Cache::forget("archetype_detect:{$match->token}:cards");
        Cache::forget("archetype_detect:{$match->token}:version");
        Cache::forget("archetype_detect:{$match->token}:player");

        // Mark all related log events as processed
        LogEvent::where(function ($query) use ($match) {
            $query->where('match_id', $match->mtgo_id)
                ->orWhere('match_token', $match->token)
                ->orWhereIn('game_id', $match->games->pluck('mtgo_id'));
        })->update([
            'processed_at' => now(),
        ]);
    }
}
