<?php

use App\Actions\Import\ReprocessImportedCardData;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Account;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('re-extracts cards from game logs and updates game_decks', function () {
    Queue::fake();

    $account = Account::factory()->create(['username' => 'LocalPlayer', 'active' => true]);
    Account::flushCurrent();
    $opponent = Opponent::factory()->create(['username' => 'Opponent']);

    $match = MtgoMatch::factory()->create([
        'imported' => true,
        'token' => 'test-token-reprocess',
        'account_id' => $account->id,
        'opponent_id' => $opponent->id,
    ]);

    $game = Game::factory()->create(['match_id' => $match->id]);

    // New schema: game_decks rows (no game_player)
    GameDeck::create(['game_id' => $game->id, 'is_opponent' => false, 'deck_json' => []]);
    GameDeck::create(['game_id' => $game->id, 'is_opponent' => true, 'deck_json' => []]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/fake/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PLocalPlayer joined the game.'],
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@POpponent joined the game.'],
            ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PLocalPlayer plays @[Urza\'s Mine@:165384,100:@].'],
            ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@POpponent casts @[Lightning Bolt@:178282,200:@].'],
        ],
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    $result = ReprocessImportedCardData::run();

    expect($result['reprocessed'])->toBe(1);

    // New schema: deck data is in game_decks rows
    $localDeck = GameDeck::where('game_id', $game->id)->where('is_opponent', false)->first();
    $oppDeck = GameDeck::where('game_id', $game->id)->where('is_opponent', true)->first();

    expect($localDeck)->not->toBeNull();
    expect($oppDeck)->not->toBeNull();

    $localIds = collect($localDeck->deck_json)->pluck('mtgo_id')->toArray();
    expect($localIds)->toContain(82692); // Urza's Mine: 165384 >> 1 = 82692

    $oppIds = collect($oppDeck->deck_json)->pluck('mtgo_id')->toArray();
    expect($oppIds)->toContain(89141); // Lightning Bolt: 178282 >> 1 = 89141

    Queue::assertPushed(DetermineMatchArchetypesJob::class, function ($job) use ($match) {
        return $job->matchId === $match->id;
    });
});

it('skips non-imported matches', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'imported' => false,
        'token' => 'non-imported-token',
    ]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/fake/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@PAlpha joined the game.'],
        ],
        'decoded_at' => now(),
        'byte_offset' => 0,
        'decoded_version' => 1,
    ]);

    $result = ReprocessImportedCardData::run();

    expect($result['reprocessed'])->toBe(0);
    Queue::assertNotPushed(DetermineMatchArchetypesJob::class);
});
