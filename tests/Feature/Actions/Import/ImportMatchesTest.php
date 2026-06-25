<?php

use App\Actions\Import\ImportMatches;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\GameCardsSnapshotChanged;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('creates match, games, and new-schema participant records from import data', function () {
    Account::factory()->create(['username' => 'anticloser', 'active' => true]);
    Account::flushCurrent();

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
                ['game_index' => 0, 'won' => true, 'on_play' => true, 'local_mulligan_count' => 0, 'opponent_mulligan_count' => 1, 'local_dice_roll' => 5, 'opponent_dice_roll' => 3, 'started_at' => '2025-06-01T12:00:00Z', 'ended_at' => '2025-06-01T12:15:00Z', 'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']], 'opponent_cards' => []],
                ['game_index' => 1, 'won' => false, 'on_play' => false, 'local_mulligan_count' => 1, 'opponent_mulligan_count' => 0, 'local_dice_roll' => 2, 'opponent_dice_roll' => 6, 'started_at' => '2025-06-01T12:16:00Z', 'ended_at' => '2025-06-01T12:30:00Z', 'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']], 'opponent_cards' => []],
                ['game_index' => 2, 'won' => true, 'on_play' => true, 'local_mulligan_count' => 0, 'opponent_mulligan_count' => 0, 'local_dice_roll' => null, 'opponent_dice_roll' => null, 'started_at' => '2025-06-01T12:31:00Z', 'ended_at' => '2025-06-01T12:45:00Z', 'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']], 'opponent_cards' => []],
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

    // New schema: account_id and opponent_id are set on the match
    expect($match->account_id)->not->toBeNull();
    expect($match->opponent_id)->not->toBeNull();

    $opponent = Opponent::find($match->opponent_id);
    expect($opponent->username)->toBe('testopponent');

    expect($match->games)->toHaveCount(3);

    // New schema: game_decks rows exist, no game_player rows
    $game1 = $match->games->sortBy('started_at')->first();
    expect($game1->decks)->toHaveCount(2);
    expect(DB::table('game_player')->where('game_id', $game1->id)->count())->toBe(0);

    $localDeck = $game1->decks->first(fn ($d) => ! $d->is_opponent);
    $oppDeck = $game1->decks->first(fn ($d) => $d->is_opponent);
    expect($localDeck)->not->toBeNull();
    expect($oppDeck)->not->toBeNull();

    // New schema: game scalar columns are set
    expect($game1->local_mulligans)->toBe(0);
    expect($game1->opp_mulligans)->toBe(1);
    expect($game1->local_dice)->toBe(5);
    expect($game1->opp_dice)->toBe(3);
    expect($game1->local_on_play)->toBeTrue();
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

it('creates per-game card stats from deck quantities when importing a match', function () {
    // Create a card that maps mtgo_id 100 → oracle_id 'oracle-card-a'
    Card::factory()->create([
        'mtgo_id' => 100,
        'name' => 'Card A',
        'oracle_id' => 'oracle-card-a',
    ]);

    // ComputeCardGameStats returns early when deck_version_id is null.
    // Provide a DeckVersion so the job can proceed; it will fall back to
    // the per-game deck_json from game_decks since the version deck has
    // no mtgo_id-keyed cards.
    $deckVersion = DeckVersion::factory()->create();

    Account::factory()->create(['username' => 'cardstatsplayer', 'active' => true]);
    Account::flushCurrent();

    $importData = [
        [
            'history_id' => 77777777,
            'started_at' => '2025-06-01T12:00:00Z',
            'opponent' => 'cardstatsopponent',
            'format_raw' => 'CMODERN',
            'games_won' => 1,
            'games_lost' => 0,
            'outcome' => 'win',
            'round' => 0,
            'has_game_log' => true,
            'game_log_token' => 'stats-token',
            'local_player' => 'cardstatsplayer',
            'games' => [
                [
                    'game_index' => 0,
                    'won' => true,
                    'on_play' => true,
                    'local_mulligan_count' => 0,
                    'opponent_mulligan_count' => 0,
                    'local_dice_roll' => 6,
                    'opponent_dice_roll' => 2,
                    'started_at' => '2025-06-01T12:00:00Z',
                    'ended_at' => '2025-06-01T12:15:00Z',
                    'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']],
                    'opponent_cards' => [],
                ],
            ],
            'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']],
            'game_ids' => [555],
            'deck_version_id' => $deckVersion->id,
        ],
    ];

    ImportMatches::run($importData);

    $match = MtgoMatch::where('mtgo_id', '77777777')->first();
    expect($match)->not->toBeNull();

    $game = $match->games->first();
    expect($game)->not->toBeNull();

    // ComputeCardGameStats runs synchronously during import (dispatchSync).
    // It should produce a local card_game_stats row for oracle-card-a.
    $stat = DB::table('card_game_stats')
        ->where('game_id', $game->id)
        ->where('oracle_id', 'oracle-card-a')
        ->where('opponent', false)
        ->first();

    expect($stat)->not->toBeNull();
    expect($stat->quantity)->toBe(1); // per-game deck_json has quantity=1 per card (fallback from version deck)
    expect((bool) $stat->won)->toBeTrue();
});

it('populates opponent deck_json in game_decks from per-game opponent cards', function () {
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

    // New schema: opponent deck is in game_decks with is_opponent=true
    $oppDeck = GameDeck::where('game_id', $game->id)->where('is_opponent', true)->first();
    expect($oppDeck)->not->toBeNull();
    expect($oppDeck->deck_json)->not->toBeNull();
    expect($oppDeck->deck_json)->toHaveCount(2);
    expect($oppDeck->deck_json[0]['mtgo_id'])->toBe(300);
    expect($oppDeck->deck_json[0]['quantity'])->toBe(1);
});

it('does not dispatch GameCardsSnapshotChanged when importing matches', function () {
    $importData = [
        [
            'history_id' => 66666666,
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
                ['game_index' => 0, 'won' => true, 'on_play' => true, 'started_at' => '2025-06-01T12:00:00Z', 'ended_at' => '2025-06-01T12:15:00Z', 'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']], 'opponent_cards' => []],
            ],
            'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']],
            'game_ids' => [111],
            'deck_version_id' => null,
        ],
    ];

    Event::fake();

    ImportMatches::run($importData);

    Event::assertNotDispatched(GameCardsSnapshotChanged::class);
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
