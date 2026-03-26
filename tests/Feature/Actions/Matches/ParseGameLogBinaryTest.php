<?php

use App\Actions\Matches\ParseGameLogBinary;
use Carbon\Carbon;

function fixturePath(string $name): string
{
    return base_path("tests/fixtures/gamelogs/{$name}");
}

it('parses a clean 2-0 win file', function () {
    $raw = file_get_contents(fixturePath('clean_2_0_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['match_uuid'])->toBe('6a0f564b-f27a-42c0-acea-464f7929342b');
    expect($result['game_uuid'])->toBe('6a0f564b-f27a-42c0-acea-464f7929342b');
    expect($result['version'])->toBe(1);
    expect($result['entries'])->toHaveCount(253);
});

it('parses a large multi-game file', function () {
    $raw = file_get_contents(fixturePath('large_2_0_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['entries'])->toHaveCount(600);
});

it('parses an instant concede file', function () {
    $raw = file_get_contents(fixturePath('instant_concede.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['entries'])->toHaveCount(8);
});

it('returns entries with timestamp and message keys', function () {
    $raw = file_get_contents(fixturePath('instant_concede.dat'));
    $result = ParseGameLogBinary::run($raw);

    $entry = $result['entries'][0];
    expect($entry)->toHaveKeys(['timestamp', 'message']);
    expect($entry['timestamp'])->toBeString();
    expect($entry['message'])->toBeString();
    expect($entry['message'])->toContain('rolled a');
});

it('produces valid timestamps', function () {
    $raw = file_get_contents(fixturePath('clean_2_0_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    $first = $result['entries'][0];
    $ts = Carbon::parse($first['timestamp']);
    expect($ts->year)->toBeGreaterThanOrEqual(2025);
    expect($ts->year)->toBeLessThanOrEqual(2027);
});

it('returns null for empty input', function () {
    expect(ParseGameLogBinary::run(''))->toBeNull();
});

it('returns null for truncated header', function () {
    expect(ParseGameLogBinary::run(str_repeat("\x00", 10)))->toBeNull();
});

it('handles incremental parsing from byte offset', function () {
    $raw = file_get_contents(fixturePath('clean_2_0_win.dat'));

    // Full parse
    $full = ParseGameLogBinary::run($raw);
    $totalEntries = count($full['entries']);

    // Parse first half by truncating file
    $halfSize = intdiv(strlen($raw), 2);
    $firstHalf = ParseGameLogBinary::run(substr($raw, 0, $halfSize));
    $firstHalfCount = count($firstHalf['entries']);
    $firstHalfOffset = $firstHalf['byte_offset'];

    expect($firstHalfCount)->toBeLessThan($totalEntries);
    expect($firstHalfOffset)->toBeLessThanOrEqual($halfSize);

    // Incremental parse from offset
    $remaining = ParseGameLogBinary::run($raw, $firstHalfOffset);
    $remainingCount = count($remaining['entries']);

    expect($firstHalfCount + $remainingCount)->toBe($totalEntries);
});

it('handles messages longer than 127 bytes with varint length', function () {
    $raw = file_get_contents(fixturePath('clean_2_1_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['entries'])->toHaveCount(329);

    $longMessages = collect($result['entries'])->filter(fn ($e) => strlen($e['message']) > 127);
    expect($longMessages)->not->toBeEmpty();
    foreach ($longMessages as $entry) {
        expect($entry['message'])->toMatch('/[\.\)\]!]$/');
    }
});
