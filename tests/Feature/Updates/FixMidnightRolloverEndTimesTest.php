<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use App\Updates\FixMidnightRolloverEndTimes;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rolls a match end time back a day when the date crossed midnight', function () {
    $match = MtgoMatch::factory()->create([
        'started_at' => '2026-07-10 23:52:56',
        'ended_at' => '2026-07-11 23:59:58',
    ]);

    (new FixMidnightRolloverEndTimes)->run();

    expect($match->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-07-10 23:59:58');
});

it('rolls a game end time back a day too', function () {
    $match = MtgoMatch::factory()->create([
        'started_at' => '2026-07-10 23:52:56',
        'ended_at' => '2026-07-10 23:59:58',
    ]);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => '2026-07-10 23:55:00',
        'ended_at' => '2026-07-11 23:59:00',
    ]);

    (new FixMidnightRolloverEndTimes)->run();

    expect($game->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-07-10 23:59:00');
});

it('leaves plausible durations alone', function () {
    $match = MtgoMatch::factory()->create([
        'started_at' => '2026-07-10 23:52:56',
        'ended_at' => '2026-07-11 00:07:12',
    ]);

    (new FixMidnightRolloverEndTimes)->run();

    expect($match->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-07-11 00:07:12');
});

it('leaves rows alone when rolling back would end the match before it started', function () {
    $match = MtgoMatch::factory()->create([
        'started_at' => '2026-07-10 10:00:00',
        'ended_at' => '2026-07-11 09:00:00',
    ]);

    (new FixMidnightRolloverEndTimes)->run();

    expect($match->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-07-11 09:00:00');
});
