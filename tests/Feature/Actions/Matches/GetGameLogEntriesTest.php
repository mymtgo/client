<?php

use App\Actions\Matches\GetGameLogEntries;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function getGameLogEntriesTest_seedMeta(string $token, LogInstance $instance, string $text, int $secondsOffset): void
{
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'match_id' => 1,
        'game_id' => 1,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:00')->addSeconds($secondsOffset),
        'byte_offset_start' => $secondsOffset * 100,
        'raw_text' => '02:00:'.str_pad((string) $secondsOffset, 2, '0', STR_PAD_LEFT).' [INF] (Game Management|Processing) Message: {"MatchToken":"'.$token.'","MatchID":1,"GameID":1,"MetaMessage":['.implode(',', $bytes).']}',
    ]);
}

it('returns entries within the game time window', function () {
    $token = 'gge-1111-1111-1111-111111111111';
    $instance = LogInstance::factory()->create();
    $match = MtgoMatch::factory()->create(['token' => $token]);
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => Carbon::parse('2026-05-26 10:00:15'),
        'ended_at' => Carbon::parse('2026-05-26 10:00:35'),
    ]);

    getGameLogEntriesTest_seedMeta($token, $instance, '@PPlayerA rolled a 1.', 1);   // before window
    getGameLogEntriesTest_seedMeta($token, $instance, '@PPlayerA rolled a 2.', 20);  // in window
    getGameLogEntriesTest_seedMeta($token, $instance, '@PPlayerA rolled a 3.', 30);  // in window
    getGameLogEntriesTest_seedMeta($token, $instance, '@PPlayerA rolled a 4.', 60);  // after window

    $entries = GetGameLogEntries::run($game);

    expect($entries)->toHaveCount(2);
});

it('returns empty array for imported match with no log_events', function () {
    $match = MtgoMatch::factory()->create(['token' => 'gge-2222-2222-2222-222222222222']);
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => Carbon::parse('2026-05-26 10:00:00'),
        'ended_at' => Carbon::parse('2026-05-26 10:30:00'),
    ]);

    expect(GetGameLogEntries::run($game))->toBe([]);
});

it('strips @P prefix from messages', function () {
    $token = 'gge-3333-3333-3333-333333333333';
    $instance = LogInstance::factory()->create();
    $match = MtgoMatch::factory()->create(['token' => $token]);
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'started_at' => Carbon::parse('2026-05-26 10:00:00'),
        'ended_at' => Carbon::parse('2026-05-26 10:30:00'),
    ]);

    getGameLogEntriesTest_seedMeta($token, $instance, '@PPlayerA rolled a 2.', 10);

    $entries = GetGameLogEntries::run($game);
    expect($entries[0]['message'])->toBe('PlayerA rolled a 2.');
});

it('returns empty array when game has no started_at or ended_at', function () {
    $match = MtgoMatch::factory()->create(['token' => 'gge-4444-4444-4444-444444444444']);
    $game = Game::factory()->make([
        'match_id' => $match->id,
        'started_at' => null,
        'ended_at' => null,
    ]);
    $game->setRelation('match', $match);

    expect(GetGameLogEntries::run($game))->toBe([]);
});
