<?php

use App\Events\DeckLinkedToMatch;
use App\Events\MatchJoined;
use App\Jobs\SyncDecks;
use App\Listeners\Pipeline\LinkMatchDeck;
use App\Models\DeckVersion;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('does nothing if match does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-nonexistent',
    ]);

    Event::fake([DeckLinkedToMatch::class]);
    Bus::fake([SyncDecks::class]);

    $listener = new LinkMatchDeck;
    $listener->handle(new MatchJoined($logEvent));

    Event::assertNotDispatched(DeckLinkedToMatch::class);
    Bus::assertNotDispatched(SyncDecks::class);
});

it('does nothing if match already has a deck linked', function () {
    $deckVersion = DeckVersion::factory()->create();

    $match = MtgoMatch::factory()->create([
        'token' => 'token-already-linked',
        'deck_version_id' => $deckVersion->id,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => $match->token,
    ]);

    Event::fake([DeckLinkedToMatch::class]);
    Bus::fake([SyncDecks::class]);

    $listener = new LinkMatchDeck;
    $listener->handle(new MatchJoined($logEvent));

    Event::assertNotDispatched(DeckLinkedToMatch::class);
    Bus::assertNotDispatched(SyncDecks::class);
    expect($match->fresh()->deck_version_id)->toBe($deckVersion->id);
});

it('dispatches SyncDecks when DetermineMatchDeck cannot find a deck', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-no-games',
        'deck_version_id' => null,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => $match->token,
    ]);

    // No game events or deck_used log events in DB — DetermineMatchDeck returns without linking.
    Bus::fake([SyncDecks::class]);
    Event::fake([DeckLinkedToMatch::class]);

    $listener = new LinkMatchDeck;
    $listener->handle(new MatchJoined($logEvent));

    Bus::assertDispatchedSync(SyncDecks::class);
    Event::assertNotDispatched(DeckLinkedToMatch::class);
    expect($match->fresh()->deck_version_id)->toBeNull();
});
