<?php

use App\Actions\Import\ImportMatches;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
                ['game_index' => 0, 'won' => true, 'on_play' => true, 'starting_hand_size' => 7, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:00:00Z', 'ended_at' => '2025-06-01T12:15:00Z', 'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']], 'opponent_cards' => []],
                ['game_index' => 1, 'won' => false, 'on_play' => false, 'starting_hand_size' => 6, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:16:00Z', 'ended_at' => '2025-06-01T12:30:00Z', 'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']], 'opponent_cards' => []],
                ['game_index' => 2, 'won' => true, 'on_play' => true, 'starting_hand_size' => 7, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:31:00Z', 'ended_at' => '2025-06-01T12:45:00Z', 'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']], 'opponent_cards' => []],
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

it('creates per-game card stats via game log when importing a match', function () {
    Card::factory()->create(['mtgo_id' => 100, 'oracle_id' => 'oracle-a', 'name' => 'Card A']);
    Card::factory()->create(['mtgo_id' => 200, 'oracle_id' => 'oracle-b', 'name' => 'Card B']);

    $deck = Deck::factory()->create();
    $signature = base64_encode('100:4:false|200:2:false');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $logToken = 'log-token-77';
    GameLog::create([
        'match_token' => $logToken,
        'file_path' => '/fake/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2025-06-01T12:00:00+00:00', 'message' => '@P@Panticloser joined the game.'],
            ['timestamp' => '2025-06-01T12:00:00+00:00', 'message' => '@P@Ptestopponent joined the game.'],
            ['timestamp' => '2025-06-01T12:00:01+00:00', 'message' => '@Panticloser rolled a 5.'],
            // Game 1: Card A cast
            ['timestamp' => '2025-06-01T12:01:00+00:00', 'message' => '@Panticloser casts @[Card A@:200,1:@].'],
            ['timestamp' => '2025-06-01T12:15:00+00:00', 'message' => '@Panticloser wins the game.'],
            // Game 2 boundary marker
            ['timestamp' => '2025-06-01T12:15:30+00:00', 'message' => '@Panticloser rolled a 3.'],
            // Game 2: A and B cast
            ['timestamp' => '2025-06-01T12:16:00+00:00', 'message' => '@Panticloser casts @[Card A@:200,2:@].'],
            ['timestamp' => '2025-06-01T12:17:00+00:00', 'message' => '@Panticloser casts @[Card B@:400,3:@].'],
            ['timestamp' => '2025-06-01T12:30:00+00:00', 'message' => '@Panticloser wins the game.'],
        ],
    ]);

    $importData = [
        [
            'history_id' => 77777777,
            'started_at' => '2025-06-01T12:00:00Z',
            'opponent' => 'testopponent',
            'format_raw' => 'CMODERN',
            'games_won' => 2,
            'games_lost' => 0,
            'outcome' => 'win',
            'round' => 0,
            'has_game_log' => true,
            'game_log_token' => $logToken,
            'local_player' => 'anticloser',
            'local_cards' => [],
            'opponent_cards' => [],
            'games' => [
                [
                    'game_index' => 0,
                    'won' => true,
                    'on_play' => true,
                    'starting_hand_size' => 7,
                    'opponent_hand_size' => 7,
                    'started_at' => '2025-06-01T12:00:00Z',
                    'ended_at' => '2025-06-01T12:15:00Z',
                    'local_cards' => [],
                    'opponent_cards' => [],
                ],
                [
                    'game_index' => 1,
                    'won' => true,
                    'on_play' => false,
                    'starting_hand_size' => 7,
                    'opponent_hand_size' => 7,
                    'started_at' => '2025-06-01T12:16:00Z',
                    'ended_at' => '2025-06-01T12:30:00Z',
                    'local_cards' => [],
                    'opponent_cards' => [],
                ],
            ],
            'game_ids' => [111, 222],
            'deck_version_id' => $version->id,
        ],
    ];

    ImportMatches::run($importData);

    $match = MtgoMatch::where('mtgo_id', '77777777')->first();

    // Game 1: Card A cast (seen=1), Card B in deck but not seen
    $game1 = $match->games->sortBy('started_at')->first();
    $game1Stats = CardGameStat::where('game_id', $game1->id)->where('opponent', false)->get();
    expect($game1Stats)->toHaveCount(2);
    expect($game1Stats->firstWhere('oracle_id', 'oracle-a')->seen)->toBe(1);
    expect($game1Stats->firstWhere('oracle_id', 'oracle-a')->cast)->toBe(1);
    expect($game1Stats->firstWhere('oracle_id', 'oracle-b')->seen)->toBe(0);

    // Game 2: both cards cast
    $game2 = $match->games->sortBy('started_at')->last();
    $game2Stats = CardGameStat::where('game_id', $game2->id)->where('opponent', false)->get();
    expect($game2Stats->firstWhere('oracle_id', 'oracle-a')->seen)->toBe(1);
    expect($game2Stats->firstWhere('oracle_id', 'oracle-b')->seen)->toBe(1);
});

it('populates opponent deck_json from per-game opponent cards', function () {
    $importData = [
        [
            'history_id' => 88888888,
            'started_at' => '2025-06-01T12:00:00Z',
            'opponent' => 'testopponent',
            'format_raw' => 'CMODERN',
            'games_won' => 2,
            'games_lost' => 0,
            'outcome' => 'win',
            'round' => 0,
            'has_game_log' => true,
            'game_log_token' => null,
            'local_player' => 'anticloser',
            'local_cards' => [],
            'opponent_cards' => [['mtgo_id' => 300, 'name' => 'Opp Card']],
            'games' => [
                [
                    'game_index' => 0,
                    'won' => true,
                    'on_play' => true,
                    'starting_hand_size' => 7,
                    'opponent_hand_size' => 7,
                    'started_at' => '2025-06-01T12:00:00Z',
                    'ended_at' => '2025-06-01T12:15:00Z',
                    'local_cards' => [],
                    'opponent_cards' => [
                        ['mtgo_id' => 300, 'name' => 'Opp Card'],
                        ['mtgo_id' => 400, 'name' => 'Opp Card 2'],
                    ],
                ],
            ],
            'game_ids' => [111],
            'deck_version_id' => null,
        ],
    ];

    ImportMatches::run($importData);

    $match = MtgoMatch::where('mtgo_id', '88888888')->first();
    $game = $match->games->first();
    $opponent = $game->opponents->first();

    expect($opponent)->not->toBeNull();
    expect($opponent->pivot->deck_json)->not->toBeNull();
    expect($opponent->pivot->deck_json)->toHaveCount(2);
    expect($opponent->pivot->deck_json[0]['mtgo_id'])->toBe(300);
    expect($opponent->pivot->deck_json[0]['quantity'])->toBe(1);
});

it('hydrateCards creates stubs and resolves oracle_ids by name', function () {
    Card::factory()->create([
        'mtgo_id' => 99999,
        'name' => 'Lightning Bolt',
        'oracle_id' => 'fake-oracle-bolt',
    ]);

    Http::fake([
        '*/api/cards/by-mtgo-id' => Http::response([], 200),
    ]);

    ImportMatches::hydrateCards([
        ['mtgo_id' => 11111, 'name' => 'Lightning Bolt'],
    ]);

    $card = Card::where('mtgo_id', 11111)->first();
    expect($card)->not->toBeNull();
    expect($card->name)->toBe('Lightning Bolt');
    expect($card->oracle_id)->toBe('fake-oracle-bolt');
});
