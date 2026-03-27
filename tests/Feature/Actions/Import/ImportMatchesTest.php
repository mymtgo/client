<?php

use App\Actions\Import\ImportMatches;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates match, games, and player records from import data', function () {
    $importData = [
        [
            'history_id' => 12345678,
            'started_at' => '2025-06-01T12:00:00Z',
            'opponent' => 'testopponent',
            'format_raw' => 'CMODERN',
            'games_won' => 2,
            'games_lost' => 1,
            'outcome' => 'win',
            'round' => 0,
            'has_game_log' => true,
            'game_log_token' => 'abc-123',
            'local_player' => 'anticloser',
            'games' => [
                ['game_index' => 0, 'won' => true, 'on_play' => true, 'starting_hand_size' => 7, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:00:00Z', 'ended_at' => '2025-06-01T12:15:00Z'],
                ['game_index' => 1, 'won' => false, 'on_play' => false, 'starting_hand_size' => 6, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:16:00Z', 'ended_at' => '2025-06-01T12:30:00Z'],
                ['game_index' => 2, 'won' => true, 'on_play' => true, 'starting_hand_size' => 7, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:31:00Z', 'ended_at' => '2025-06-01T12:45:00Z'],
            ],
            'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']],
            'game_ids' => [111, 222, 333],
            'deck_version_id' => null,
        ],
    ];

    $result = ImportMatches::run($importData);

    expect($result['imported'])->toBe(1);

    $match = MtgoMatch::where('mtgo_id', '12345678')->first();
    expect($match)->not->toBeNull();
    expect($match->imported)->toBeTrue();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Win);
    expect($match->games_won)->toBe(2);
    expect($match->games_lost)->toBe(1);
    expect($match->format)->toBe('CMODERN');

    expect($match->games)->toHaveCount(3);

    $game1 = $match->games->sortBy('started_at')->first();
    expect($game1->players)->toHaveCount(2);

    $local = $game1->players->first(fn ($p) => $p->pivot->is_local);
    expect($local->username)->toBe('anticloser');
    expect($local->pivot->on_play)->toBeTrue();
});

it('creates match without games when no game log available', function () {
    $importData = [
        [
            'history_id' => 99999999,
            'started_at' => '2025-07-01T12:00:00Z',
            'opponent' => 'unknownplayer',
            'format_raw' => 'CPAUPER',
            'games_won' => 0,
            'games_lost' => 2,
            'outcome' => 'loss',
            'round' => 0,
            'has_game_log' => false,
            'game_log_token' => null,
            'local_player' => null,
            'games' => null,
            'local_cards' => null,
            'game_ids' => [],
            'deck_version_id' => null,
        ],
    ];

    ImportMatches::run($importData);

    $match = MtgoMatch::where('mtgo_id', '99999999')->first();
    expect($match)->not->toBeNull();
    expect($match->imported)->toBeTrue();
    expect($match->games)->toHaveCount(0);
});

it('skips duplicate mtgo_ids', function () {
    MtgoMatch::factory()->create(['mtgo_id' => '55555555']);

    $importData = [
        [
            'history_id' => 55555555,
            'started_at' => '2025-08-01T12:00:00Z',
            'opponent' => 'dup',
            'format_raw' => 'CMODERN',
            'games_won' => 1,
            'games_lost' => 0,
            'outcome' => 'win',
            'round' => 0,
            'has_game_log' => false,
            'game_log_token' => null,
            'local_player' => null,
            'games' => null,
            'local_cards' => null,
            'game_ids' => [],
            'deck_version_id' => null,
        ],
    ];

    $result = ImportMatches::run($importData);
    expect($result['skipped'])->toBe(1);
});
