<?php

namespace App\Listeners\Pipeline;

use App\Actions\DetermineMatchArchetypes;
use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\GetGameLog;
use App\Actions\Matches\SyncGameResults;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Events\MatchEnded;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\SubmitMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class CompleteMatch
{
    public function handle(MatchEnded $event): void
    {
        $logEvent = $event->logEvent;
        $match = MtgoMatch::where('token', $logEvent->match_token)->first();

        if (! $match || $match->state !== MatchState::Ended) {
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

        $match->update([
            'games_won' => $result['wins'],
            'games_lost' => $result['losses'],
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

        // Post-completion: each independent, non-critical
        try {
            DetermineMatchArchetypes::run($match);
        } catch (\Throwable $e) {
            Log::warning("Failed to determine archetypes for match {$match->id}: {$e->getMessage()}");
        }

        SubmitMatch::dispatch($match->id);
        ComputeCardGameStats::dispatch($match->id);

        $won = $match->games_won > $match->games_lost;
        $opponentArchetype = $match->opponentArchetypes()->with('archetype')->first()?->archetype?->name ?? 'Unknown';

        AppNotification::dispatch(
            type: $won ? 'match_win' : 'match_loss',
            title: ($won ? 'Win' : 'Loss').' vs '.$opponentArchetype,
            message: $match->games_won.'-'.$match->games_lost,
            route: '/matches/'.$match->id,
        );

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
