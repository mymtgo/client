<?php

use App\Actions\Pipeline\DispatchDomainEvents;
use App\Jobs\EstimateArchetypeJob;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('game_state_update event triggers opponent card extraction and job dispatch', function () {
    Queue::fake();

    $event = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'integration-tok',
        'username' => 'LocalPlayer',
        'raw_text' => json_encode([
            'Players' => [
                ['Id' => 1, 'Name' => 'LocalPlayer'],
                ['Id' => 2, 'Name' => 'OpponentPlayer'],
            ],
            'Cards' => [
                ['CatalogID' => 12345, 'Owner' => 2],
                ['CatalogID' => 67890, 'Owner' => 2],
                ['CatalogID' => 11111, 'Owner' => 1],
            ],
        ]),
    ]);

    DispatchDomainEvents::run(collect([$event]));

    $cards = Cache::get('archetype_detect:integration-tok:cards');
    expect($cards)->toHaveCount(2);

    $mtgoIds = array_column($cards, 'mtgo_id');
    expect($mtgoIds)->toContain(12345);
    expect($mtgoIds)->toContain(67890);

    Queue::assertPushed(EstimateArchetypeJob::class, function ($job) {
        return $job->matchToken === 'integration-tok';
    });
});

it('multiple game state events replace cache with latest state', function () {
    Queue::fake();

    $events = [];

    // First state: 1 opponent card
    $events[] = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'accum-tok',
        'username' => 'LocalPlayer',
        'raw_text' => json_encode([
            'Players' => [
                ['Id' => 1, 'Name' => 'LocalPlayer'],
                ['Id' => 2, 'Name' => 'Opponent'],
            ],
            'Cards' => [
                ['CatalogID' => 111, 'Owner' => 2],
            ],
        ]),
    ]);

    // Second state: 3 opponent cards (full replacement)
    $events[] = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'accum-tok',
        'username' => 'LocalPlayer',
        'raw_text' => json_encode([
            'Players' => [
                ['Id' => 1, 'Name' => 'LocalPlayer'],
                ['Id' => 2, 'Name' => 'Opponent'],
            ],
            'Cards' => [
                ['CatalogID' => 111, 'Owner' => 2],
                ['CatalogID' => 222, 'Owner' => 2],
                ['CatalogID' => 333, 'Owner' => 2],
            ],
        ]),
    ]);

    foreach ($events as $event) {
        DispatchDomainEvents::run(collect([$event]));
    }

    $cards = Cache::get('archetype_detect:accum-tok:cards');
    expect($cards)->toHaveCount(3);

    $version = Cache::get('archetype_detect:accum-tok:version');
    expect($version)->toBe(2);
});
