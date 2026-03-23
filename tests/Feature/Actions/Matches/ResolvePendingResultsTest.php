<?php

use App\Actions\Matches\ParseMatchHistory;
use App\Actions\Matches\ResolvePendingResults;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('leaves PendingResult matches unchanged when parser returns null', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::PendingResult]);

    ResolvePendingResults::run();

    expect($match->fresh()->state)->toBe(MatchState::PendingResult);
});

it('skips matches not in PendingResult state', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    ResolvePendingResults::run();

    expect($match->fresh()->state)->toBe(MatchState::Ended);
});

it('does nothing when no PendingResult matches exist', function () {
    ResolvePendingResults::run();

    expect(MtgoMatch::count())->toBe(0);
});

it('finds result by mtgo_id from game history', function () {
    // Test ParseMatchHistory directly with real fixture data
    $historyPath = storage_path('app/91F5DC46A0AFBF283E8FD4E9E184F175/mtgo_game_history');

    if (! file_exists($historyPath)) {
        $this->markTestSkipped('mtgo_game_history fixture not available');
    }

    // Use a known match ID from the fixture (first record)
    $result = ParseMatchHistory::findResult('278248231', $historyPath);

    expect($result)->not->toBeNull()
        ->and($result['wins'])->toBe(0)
        ->and($result['losses'])->toBe(2);
});

it('returns null for unknown match id', function () {
    $historyPath = storage_path('app/91F5DC46A0AFBF283E8FD4E9E184F175/mtgo_game_history');

    if (! file_exists($historyPath)) {
        $this->markTestSkipped('mtgo_game_history fixture not available');
    }

    $result = ParseMatchHistory::findResult('000000000', $historyPath);

    expect($result)->toBeNull();
});
