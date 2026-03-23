<?php

namespace App\Observers;

use App\Actions\DetermineMatchArchetypes;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\SubmitMatch;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MtgoMatchObserver
{
    /**
     * Trigger enrichment when a match transitions to Complete.
     */
    public function updated(MtgoMatch $match): void
    {
        if (! $match->isDirty('state') || $match->state !== MatchState::Complete) {
            return;
        }

        // Each enrichment is independent — failure in one doesn't block others
        try {
            DetermineMatchArchetypes::run($match);
        } catch (\Throwable $e) {
            Log::warning("Enrichment failed: archetypes for match {$match->id}: {$e->getMessage()}");
        }

        try {
            SubmitMatch::dispatch($match->id);
        } catch (\Throwable $e) {
            Log::warning("Enrichment failed: submit for match {$match->id}: {$e->getMessage()}");
        }

        try {
            ComputeCardGameStats::dispatch($match->id);
        } catch (\Throwable $e) {
            Log::warning("Enrichment failed: card stats for match {$match->id}: {$e->getMessage()}");
        }

        // Notification
        $won = $match->outcome === MatchOutcome::Win;
        $opponentArchetype = $match->opponentArchetypes()
            ->with('archetype')
            ->first()?->archetype?->name ?? 'Unknown';

        AppNotification::dispatch(
            type: $won ? 'match_win' : 'match_loss',
            title: ($won ? 'Win' : 'Loss').' vs '.$opponentArchetype,
            message: $match->games_won.'-'.$match->games_lost,
            route: '/matches/'.$match->id,
        );

        // League completion check
        if (($league = $match->league) && $league->state === LeagueState::Active
            && $league->matches()->where('state', MatchState::Complete)->count() >= 5) {
            $league->update(['state' => LeagueState::Complete]);
        }
    }

    /**
     * Clean up all related records when a match is permanently deleted.
     */
    public function deleting(MtgoMatch $match): void
    {
        $gameIds = $match->games()->pluck('id');
        $gameMtgoIds = $match->games()->pluck('mtgo_id');

        // match_archetypes
        DB::table('match_archetypes')
            ->where('mtgo_match_id', $match->id)
            ->delete();

        // card_game_stats
        if ($gameIds->isNotEmpty()) {
            DB::table('card_game_stats')
                ->whereIn('game_id', $gameIds)
                ->delete();
        }

        // game_timelines
        if ($gameIds->isNotEmpty()) {
            DB::table('game_timelines')
                ->whereIn('game_id', $gameIds)
                ->delete();
        }

        // game_player
        if ($gameIds->isNotEmpty()) {
            DB::table('game_player')
                ->whereIn('game_id', $gameIds)
                ->delete();
        }

        // games
        Game::where('match_id', $match->id)->delete();

        // log events
        LogEvent::where(function ($q) use ($match, $gameMtgoIds) {
            $q->where('match_id', $match->mtgo_id)
                ->orWhere('match_token', $match->token);

            if ($gameMtgoIds->isNotEmpty()) {
                $q->orWhereIn('game_id', $gameMtgoIds);
            }
        })->delete();
    }
}
