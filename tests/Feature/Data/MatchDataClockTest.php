<?php

use App\Data\Front\MatchData;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, array{started_at: string, clock: ?int}>  $games
 */
function matchWithClocks(array $games): MtgoMatch
{
    $match = MtgoMatch::factory()->won()->create();
    $local = Player::factory()->create();
    $opponent = Player::factory()->create();

    foreach ($games as $data) {
        $game = Game::factory()->create(['match_id' => $match->id, 'started_at' => $data['started_at'], 'won' => true]);
        $game->players()->attach($local->id, ['instance_id' => 0, 'is_local' => true, 'clock_remaining_ms_end' => $data['clock']]);
        $game->players()->attach($opponent->id, ['instance_id' => 1, 'is_local' => false, 'clock_remaining_ms_end' => 900000]);
    }

    return $match->load('games.players');
}

it('reports the local clock left at the end of the last game', function () {
    // Games inserted out of order: the latest started_at is the match clock.
    $match = matchWithClocks([
        ['started_at' => '2026-09-25 14:30:00', 'clock' => 604000],
        ['started_at' => '2026-09-25 14:00:00', 'clock' => 1403000],
        ['started_at' => '2026-09-25 14:15:00', 'clock' => 1037000],
    ]);

    expect(MatchData::fromModel($match)->clockRemainingMs->resolve())->toBe(604000);
});

it('reports no match clock when the last game has none', function () {
    $match = matchWithClocks([
        ['started_at' => '2026-09-25 14:00:00', 'clock' => 1403000],
        ['started_at' => '2026-09-25 14:15:00', 'clock' => null],
    ]);

    expect(MatchData::fromModel($match)->clockRemainingMs->resolve())->toBeNull();
});

it('reports the opponent clock left at the end of the last game', function () {
    $match = matchWithClocks([
        ['started_at' => '2026-09-25 14:00:00', 'clock' => 1403000],
    ]);

    expect(MatchData::fromModel($match)->opponentClockRemainingMs->resolve())->toBe(900000);
});
