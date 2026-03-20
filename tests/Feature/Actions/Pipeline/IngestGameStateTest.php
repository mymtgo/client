<?php

use App\Actions\Pipeline\IngestGameState;
use App\Facades\Mtgo;
use App\Models\GameLogCursor;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function gameLogFixture(string $name): string
{
    return base_path("tests/fixtures/gamelogs/{$name}");
}

it('returns early for a non-existent file', function () {
    IngestGameState::run('/tmp/does_not_exist_abc123.dat');

    expect(GameLogCursor::count())->toBe(0);
    expect(LogEvent::count())->toBe(0);
});

it('creates a GameLogCursor for a new file', function () {
    $path = gameLogFixture('instant_concede.dat');

    Mtgo::shouldReceive('getUsername')->andReturn(null);

    IngestGameState::run($path);

    expect(GameLogCursor::where('file_path', $path)->exists())->toBeTrue();
});

it('extracts the match token from the filename', function () {
    // Copy fixture to a tmp path with a proper game log filename
    $token = 'abc-token-123';
    $tmpPath = sys_get_temp_dir()."/Match_GameLog_{$token}.dat";
    copy(gameLogFixture('instant_concede.dat'), $tmpPath);

    Mtgo::shouldReceive('getUsername')->andReturn(null);

    IngestGameState::run($tmpPath);

    $cursor = GameLogCursor::where('file_path', $tmpPath)->first();
    expect($cursor)->not->toBeNull();
    expect($cursor->match_token)->toBe($token);

    unlink($tmpPath);
});

it('creates game_result log events when local player is known', function () {
    Event::fake();

    $path = gameLogFixture('clean_2_0_win.dat');

    Mtgo::shouldReceive('getUsername')->andReturn('TestPlayer');

    IngestGameState::run($path);

    $events = LogEvent::where('event_type', 'game_result')->get();
    expect($events->count())->toBeGreaterThanOrEqual(1);
});

it('is idempotent — second call with same file produces no new events', function () {
    Event::fake();

    $path = gameLogFixture('clean_2_0_win.dat');

    Mtgo::shouldReceive('getUsername')->atLeast()->once()->andReturn('TestPlayer');

    IngestGameState::run($path);
    $countAfterFirst = LogEvent::where('event_type', 'game_result')->count();
    $offsetAfterFirst = GameLogCursor::where('file_path', $path)->value('byte_offset');

    // Second call: cursor is already at end, so no new data parsed
    IngestGameState::run($path);
    $countAfterSecond = LogEvent::where('event_type', 'game_result')->count();
    $offsetAfterSecond = GameLogCursor::where('file_path', $path)->value('byte_offset');

    expect($countAfterSecond)->toBe($countAfterFirst);
    expect($offsetAfterSecond)->toBe($offsetAfterFirst);
});

it('skips game result creation when no local player is configured', function () {
    $path = gameLogFixture('instant_concede.dat');

    Mtgo::shouldReceive('getUsername')->andReturn(null);

    IngestGameState::run($path);

    expect(LogEvent::where('event_type', 'game_result')->count())->toBe(0);

    // But the cursor is still created and advanced
    expect(GameLogCursor::where('file_path', $path)->first()->byte_offset)->toBeGreaterThan(0);
});

it('creates card_revealed log events for opponent cards', function () {
    Event::fake();

    $path = gameLogFixture('clean_2_0_win.dat');

    // anticloser is local player; Bordas99 is the opponent
    Mtgo::shouldReceive('getUsername')->andReturn('anticloser');

    IngestGameState::run($path);

    $events = LogEvent::where('event_type', 'card_revealed')->get();
    expect($events->count())->toBeGreaterThan(0);

    // Every card_revealed event should be for the opponent, not the local player
    foreach ($events as $event) {
        $data = json_decode($event->raw_text, true);
        expect($data['player'])->toBe('Bordas99');
        expect($data['card_name'])->toBeString()->not->toBeEmpty();
        expect($data['action'])->toBeIn(['casts', 'plays']);
    }
});

it('excludes local player cards from card_revealed events', function () {
    Event::fake();

    $path = gameLogFixture('clean_2_0_win.dat');

    // Set local player to anticloser — their cards should NOT appear
    Mtgo::shouldReceive('getUsername')->andReturn('anticloser');

    IngestGameState::run($path);

    $events = LogEvent::where('event_type', 'card_revealed')->get();

    foreach ($events as $event) {
        $data = json_decode($event->raw_text, true);
        expect($data['player'])->not->toBe('anticloser');
    }
});

it('skips card_revealed events when no local player is configured', function () {
    $path = gameLogFixture('clean_2_0_win.dat');

    Mtgo::shouldReceive('getUsername')->andReturn(null);

    IngestGameState::run($path);

    expect(LogEvent::where('event_type', 'card_revealed')->count())->toBe(0);
});
