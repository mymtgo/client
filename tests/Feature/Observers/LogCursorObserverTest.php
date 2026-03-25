<?php

use App\Enums\MatchState;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

it('does not trigger on normal cursor advance', function () {
    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 1000]);
    $match = MtgoMatch::factory()->ended()->create();

    $cursor->update(['byte_offset' => 2000]);

    expect($match->fresh()->state)->toBe(MatchState::Ended);
});

it('does not trigger when cursor is first created', function () {
    $match = MtgoMatch::factory()->ended()->create();

    LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 0]);

    expect($match->fresh()->state)->toBe(MatchState::Ended);
});

it('skips failed matches on cursor reset', function () {
    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 5000]);
    $failedMatch = MtgoMatch::factory()->ended()->failed()->create();

    $cursor->update(['byte_offset' => 0]);

    expect($failedMatch->fresh()->state)->toBe(MatchState::Ended);
});

it('does not touch Complete matches on cursor reset', function () {
    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 5000]);
    $complete = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $cursor->update(['byte_offset' => 0]);

    expect($complete->fresh()->state)->toBe(MatchState::Complete);
});

it('resolves incomplete matches from match history on cursor reset', function () {
    $historyPath = base_path('tests/fixtures/mtgo_game_history');

    if (! file_exists($historyPath)) {
        $this->markTestSkipped('mtgo_game_history fixture not available');
    }

    // Point Mtgo::getLogDataPath() at the fixtures directory so ParseGameHistory finds the file
    Settings::set('log_data_path', base_path('tests/fixtures'));
    Cache::forget('mtgo.game_history');

    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 5000]);

    // 278248231 exists in the fixture with 0 wins, 2 losses
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '278248231']);

    $cursor->update(['byte_offset' => 0]);

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome->value)->toBe('loss');
});

it('leaves matches unchanged when not in match history', function () {
    $historyPath = base_path('tests/fixtures/mtgo_game_history');

    if (! file_exists($historyPath)) {
        $this->markTestSkipped('mtgo_game_history fixture not available');
    }

    Settings::set('log_data_path', base_path('tests/fixtures'));
    Cache::forget('mtgo.game_history');

    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 5000]);
    $match = MtgoMatch::factory()->started()->create(['mtgo_id' => '000000000']);

    $cursor->update(['byte_offset' => 0]);

    expect($match->fresh()->state)->toBe(MatchState::Started);
});
