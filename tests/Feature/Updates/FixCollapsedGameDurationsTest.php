<?php

use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Updates\FixCollapsedGameDurations;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Two games' worth of entries: a win ends the first, and the roll that follows
 * opens the second — the boundary ExtractGameResults splits on.
 *
 * @return array<int, array{timestamp: string, message: string}>
 */
function collapsedDurationsEntries(): array
{
    return [
        ['timestamp' => '2026-06-09T19:38:57+00:00', 'message' => '@P@Pme joined the game.'],
        ['timestamp' => '2026-06-09T19:38:58+00:00', 'message' => '@P@Popp joined the game.'],
        ['timestamp' => '2026-06-09T19:39:10+00:00', 'message' => '@Pme chooses to play first.'],
        ['timestamp' => '2026-06-09T19:47:02+00:00', 'message' => '@Pme wins the game.'],
        ['timestamp' => '2026-06-09T19:48:10+00:00', 'message' => '@Pme rolled a 3.'],
        ['timestamp' => '2026-06-09T19:48:11+00:00', 'message' => '@Popp rolled a 1.'],
        ['timestamp' => '2026-06-09T19:48:59+00:00', 'message' => '@Popp wins the game.'],
    ];
}

it('recovers real game times from the decoded log', function () {
    $match = MtgoMatch::factory()->create(['token' => 'aaaaaaaa-1111-2222-3333-444444444444']);

    // Both games frozen a second after they began, as the old write-once
    // ended_at left them.
    $first = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => '2026-06-09 19:38:57',
        'ended_at' => '2026-06-09 19:38:57',
    ]);
    $second = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => '2026-06-09 19:48:10',
        'ended_at' => '2026-06-09 19:48:11',
    ]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => "/logs/{$match->token}.dat",
        'decoded_entries' => collapsedDurationsEntries(),
    ]);

    (new FixCollapsedGameDurations)->run();

    expect($first->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-06-09 19:47:02')
        ->and($second->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-06-09 19:48:59');
});

it('leaves the match alone when the games and log groups disagree', function () {
    $match = MtgoMatch::factory()->create(['token' => 'bbbbbbbb-1111-2222-3333-444444444444']);

    // Only one game row for a two-game log: pairing by position would hand
    // game 1 the whole match.
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => '2026-06-09 19:38:57',
        'ended_at' => '2026-06-09 19:38:57',
    ]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => "/logs/{$match->token}.dat",
        'decoded_entries' => collapsedDurationsEntries(),
    ]);

    (new FixCollapsedGameDurations)->run();

    expect($game->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-06-09 19:38:57');
});

it('leaves games with plausible durations alone', function () {
    $match = MtgoMatch::factory()->create(['token' => 'cccccccc-1111-2222-3333-444444444444']);

    $first = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => '2026-06-09 19:38:57',
        'ended_at' => '2026-06-09 19:46:00',
    ]);
    Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => '2026-06-09 19:48:10',
        'ended_at' => '2026-06-09 19:48:59',
    ]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => "/logs/{$match->token}.dat",
        'decoded_entries' => collapsedDurationsEntries(),
    ]);

    (new FixCollapsedGameDurations)->run();

    expect($first->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-06-09 19:46:00');
});

it('does nothing when no decoded log survives', function () {
    $match = MtgoMatch::factory()->create(['token' => 'dddddddd-1111-2222-3333-444444444444']);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => '2026-06-09 19:38:57',
        'ended_at' => '2026-06-09 19:38:57',
    ]);

    (new FixCollapsedGameDurations)->run();

    expect($game->fresh()->ended_at->format('Y-m-d H:i:s'))->toBe('2026-06-09 19:38:57');
});
