<?php

namespace App\Actions\Debug;

use App\Enums\LogEventType;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;

class AdvanceFakeOverlayPhase
{
    /**
     * Move a fake overlay match between the states the overlay reacts to.
     *
     * `sideboarding` ends the latest game and plants a pre-processed
     * SideboardingState log event, so DetectSideboarding flips true and the
     * overlay auto-switches tabs. `game2` starts the next game with a timeline
     * snapshot, which flips it back.
     */
    public static function run(MtgoMatch $match, string $phase): void
    {
        if ($phase === 'sideboarding') {
            self::enterSideboarding($match);

            return;
        }

        self::startNextGame($match);
    }

    private static function enterSideboarding(MtgoMatch $match): void
    {
        $match->games()->latest('started_at')->first()?->update([
            'ended_at' => now()->subMinute(),
        ]);

        $instance = LogInstance::firstOrCreate(
            ['identity_hash' => 'fake-overlay'],
            [
                'file_path' => 'fake-overlay.log',
                'head_hash' => 'fake-overlay',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        // processed_at is pre-set so the pipeline never tries to project this
        // event into a real match state transition.
        LogEvent::create([
            'log_instance_id' => $instance->id,
            'file_path' => 'fake-overlay.log',
            'byte_offset_start' => 0,
            'byte_offset_end' => 1,
            'timestamp' => now()->format('H:i:s'),
            'logged_at' => now(),
            'level' => 'INF',
            'category' => 'Game Management',
            'context' => 'Match State Changed from MatchJoinedGameStartedState to MatchJoinedSideboardingState',
            'raw_text' => 'Fake overlay simulator: Match State Changed for '.$match->token
                .' from MatchJoinedGameStartedState to MatchJoinedSideboardingState',
            'ingested_at' => now(),
            'processed_at' => now(),
            'match_token' => $match->token,
            'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        ]);
    }

    private static function startNextGame(MtgoMatch $match): void
    {
        $previous = $match->games()->latest('started_at')->first();

        if (! $previous) {
            return;
        }

        $game = Game::create([
            'match_id' => $match->id,
            'mtgo_id' => 'fake-g'.($match->games()->count() + 1).'-'.$match->id,
            'started_at' => now(),
        ]);

        foreach ($previous->players as $player) {
            $game->players()->attach($player->id, [
                'is_local' => $player->pivot->is_local,
                'instance_id' => $player->pivot->instance_id,
                'deck_json' => $player->pivot->deck_json ?? [],
            ]);
        }

        // A snapshot is what tells DetectSideboarding the next game is
        // actually underway.
        $game->timeline()->create([
            'timestamp' => now(),
            'content' => ['Players' => []],
        ]);
    }
}
