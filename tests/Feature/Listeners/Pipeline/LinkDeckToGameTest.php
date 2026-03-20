<?php

use App\Enums\MatchState;
use App\Events\DeckUsedInGame;
use App\Listeners\Pipeline\LinkDeckToGame;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks event as processed when game exists', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-deck-link',
        'state' => MatchState::InProgress,
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 77,
        'won' => null,
        'started_at' => now(),
        'ended_at' => null,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-deck-link',
        'game_id' => 77,
        'event_type' => 'deck_used',
        'raw_text' => json_encode([['CatalogId' => 1, 'Quantity' => 4, 'InSideboard' => false]]),
        'processed_at' => null,
    ]);

    $listener = new LinkDeckToGame;
    $listener->handle(new DeckUsedInGame($logEvent));

    $logEvent->refresh();
    expect($logEvent->processed_at)->not->toBeNull();
});

it('does nothing if game does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-no-game',
        'game_id' => 888,
        'event_type' => 'deck_used',
        'processed_at' => null,
    ]);

    $listener = new LinkDeckToGame;
    $listener->handle(new DeckUsedInGame($logEvent));

    $logEvent->refresh();
    // processed_at should remain null since there was no game to process
    expect($logEvent->processed_at)->toBeNull();
});
