<?php

use App\Actions\Import\ReprocessImportedCardData;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('re-extracts cards from game logs and dispatches archetype detection', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'imported' => true,
        'token' => 'test-token-reprocess',
    ]);

    $localPlayer = Player::create(['username' => 'LocalPlayer']);
    $opponent = Player::create(['username' => 'Opponent']);

    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($localPlayer->id, [
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'instance_id' => 0,
        'deck_json' => [],
    ]);
    $game->players()->attach($opponent->id, [
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'instance_id' => 0,
        'deck_json' => [],
    ]);

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

    $localPivot = $game->fresh()->players()->wherePivot('is_local', true)->first();
    $localIds = collect($localPivot->pivot->deck_json)->pluck('mtgo_id')->toArray();
    expect($localIds)->toContain(82692); // Urza's Mine: 165384 >> 1 = 82692

    $oppPivot = $game->fresh()->players()->wherePivot('is_local', false)->first();
    $oppIds = collect($oppPivot->pivot->deck_json)->pluck('mtgo_id')->toArray();
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
