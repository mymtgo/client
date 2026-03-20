<?php

use App\Events\GameStateChanged;
use App\Jobs\EstimateArchetypeJob;
use App\Listeners\Pipeline\DetectArchetype;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeGameStateRawText(array $players, array $cards): string
{
    return json_encode([
        'Players' => $players,
        'Cards' => $cards,
    ]);
}

it('extracts opponent cards from game state and dispatches EstimateArchetypeJob', function () {
    Queue::fake();

    $rawText = makeGameStateRawText(
        [
            ['Id' => 1, 'Name' => 'LocalPlayer'],
            ['Id' => 2, 'Name' => 'OpponentPlayer'],
        ],
        [
            ['CatalogID' => 12345, 'Owner' => 2],
            ['CatalogID' => 67890, 'Owner' => 2],
            ['CatalogID' => 11111, 'Owner' => 1],
        ]
    );

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-1',
        'event_type' => 'game_state_update',
        'username' => 'LocalPlayer',
        'raw_text' => $rawText,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new GameStateChanged($logEvent));

    $cards = Cache::get('archetype_detect:token-detect-1:cards');
    expect($cards)->toHaveCount(2);

    $mtgoIds = array_column($cards, 'mtgo_id');
    expect($mtgoIds)->toContain(12345);
    expect($mtgoIds)->toContain(67890);
    expect($mtgoIds)->not->toContain(11111);

    expect(Cache::get('archetype_detect:token-detect-1:player'))->toBe('OpponentPlayer');

    Queue::assertPushed(EstimateArchetypeJob::class, function ($job) {
        return $job->matchToken === 'token-detect-1' && $job->version === 1;
    });
});

it('caps quantity at 4 for duplicate cards', function () {
    Queue::fake();

    // 5 copies of the same card (opponent owns all)
    $cards = [];
    for ($i = 0; $i < 5; $i++) {
        $cards[] = ['CatalogID' => 12345, 'Owner' => 2];
    }

    $rawText = makeGameStateRawText(
        [
            ['Id' => 1, 'Name' => 'LocalPlayer'],
            ['Id' => 2, 'Name' => 'Opponent'],
        ],
        $cards
    );

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-2',
        'event_type' => 'game_state_update',
        'username' => 'LocalPlayer',
        'raw_text' => $rawText,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new GameStateChanged($logEvent));

    $cached = Cache::get('archetype_detect:token-detect-2:cards');
    expect($cached)->toHaveCount(1);
    expect($cached[0]['quantity'])->toBe(4);
});

it('replaces cache with latest state on each event', function () {
    Queue::fake();

    $listener = new DetectArchetype;

    // First event: one opponent card
    $logEvent1 = LogEvent::factory()->create([
        'match_token' => 'token-detect-3',
        'event_type' => 'game_state_update',
        'username' => 'LocalPlayer',
        'raw_text' => makeGameStateRawText(
            [['Id' => 1, 'Name' => 'LocalPlayer'], ['Id' => 2, 'Name' => 'Opp']],
            [['CatalogID' => 111, 'Owner' => 2]]
        ),
    ]);
    $listener->handle(new GameStateChanged($logEvent1));

    expect(Cache::get('archetype_detect:token-detect-3:cards'))->toHaveCount(1);

    // Second event: two opponent cards (full state replacement)
    $logEvent2 = LogEvent::factory()->create([
        'match_token' => 'token-detect-3',
        'event_type' => 'game_state_update',
        'username' => 'LocalPlayer',
        'raw_text' => makeGameStateRawText(
            [['Id' => 1, 'Name' => 'LocalPlayer'], ['Id' => 2, 'Name' => 'Opp']],
            [['CatalogID' => 111, 'Owner' => 2], ['CatalogID' => 222, 'Owner' => 2]]
        ),
    ]);
    $listener->handle(new GameStateChanged($logEvent2));

    $cards = Cache::get('archetype_detect:token-detect-3:cards');
    expect($cards)->toHaveCount(2);
    expect(Cache::get('archetype_detect:token-detect-3:version'))->toBe(2);
});

it('excludes local player cards', function () {
    Queue::fake();

    $rawText = makeGameStateRawText(
        [
            ['Id' => 1, 'Name' => 'LocalPlayer'],
            ['Id' => 2, 'Name' => 'Opponent'],
        ],
        [
            ['CatalogID' => 11111, 'Owner' => 1],
            ['CatalogID' => 22222, 'Owner' => 1],
        ]
    );

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-4',
        'event_type' => 'game_state_update',
        'username' => 'LocalPlayer',
        'raw_text' => $rawText,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new GameStateChanged($logEvent));

    // No opponent cards, so nothing cached
    expect(Cache::get('archetype_detect:token-detect-4:cards'))->toBeNull();
    Queue::assertNotPushed(EstimateArchetypeJob::class);
});

it('does nothing for events without a match_token', function () {
    Queue::fake();

    $logEvent = LogEvent::factory()->create([
        'match_token' => null,
        'event_type' => 'game_state_update',
        'username' => 'LocalPlayer',
        'raw_text' => makeGameStateRawText(
            [['Id' => 1, 'Name' => 'LocalPlayer'], ['Id' => 2, 'Name' => 'Opp']],
            [['CatalogID' => 111, 'Owner' => 2]]
        ),
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new GameStateChanged($logEvent));

    Queue::assertNotPushed(EstimateArchetypeJob::class);
});

it('does nothing without a local player configured', function () {
    Queue::fake();

    // No username on the event and no active Account
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-6',
        'event_type' => 'game_state_update',
        'username' => null,
        'raw_text' => makeGameStateRawText(
            [['Id' => 1, 'Name' => 'SomePlayer'], ['Id' => 2, 'Name' => 'Opp']],
            [['CatalogID' => 111, 'Owner' => 2]]
        ),
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new GameStateChanged($logEvent));

    Queue::assertNotPushed(EstimateArchetypeJob::class);
});

it('does nothing when JSON has no Players or Cards', function () {
    Queue::fake();

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-7',
        'event_type' => 'game_state_update',
        'username' => 'LocalPlayer',
        'raw_text' => json_encode(['Players' => [], 'Cards' => []]),
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new GameStateChanged($logEvent));

    Queue::assertNotPushed(EstimateArchetypeJob::class);
});

it('increments version counter on each game state event', function () {
    Queue::fake();

    $listener = new DetectArchetype;
    $token = 'token-detect-8';

    for ($i = 0; $i < 3; $i++) {
        $logEvent = LogEvent::factory()->create([
            'match_token' => $token,
            'event_type' => 'game_state_update',
            'username' => 'LocalPlayer',
            'raw_text' => makeGameStateRawText(
                [['Id' => 1, 'Name' => 'LocalPlayer'], ['Id' => 2, 'Name' => 'Opp']],
                [['CatalogID' => 111 + $i, 'Owner' => 2]]
            ),
        ]);
        $listener->handle(new GameStateChanged($logEvent));
    }

    expect(Cache::get("archetype_detect:{$token}:version"))->toBe(3);
    Queue::assertPushed(EstimateArchetypeJob::class, 3);
});
