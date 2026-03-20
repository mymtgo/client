<?php

use App\Actions\Pipeline\DispatchDomainEvents;
use App\Jobs\EstimateArchetypeJob;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('card_revealed event triggers cache accumulation and job dispatch', function () {
    Queue::fake();

    $event = LogEvent::factory()->create([
        'event_type' => 'card_revealed',
        'match_token' => 'integration-tok',
        'raw_text' => json_encode([
            'player' => 'OpponentPlayer',
            'card_name' => 'Lightning Bolt',
            'action' => 'casts',
        ]),
    ]);

    DispatchDomainEvents::run(collect([$event]));

    $cards = Cache::get('archetype_detect:integration-tok:cards');
    expect($cards)->toHaveCount(1);
    expect($cards[0]['card_name'])->toBe('Lightning Bolt');

    Queue::assertPushed(EstimateArchetypeJob::class, function ($job) {
        return $job->matchToken === 'integration-tok';
    });
});

it('multiple cards accumulate before job fires', function () {
    Queue::fake();

    $cardNames = ['Lightning Bolt', 'Goblin Guide', 'Monastery Swiftspear', 'Eidolon of the Great Revel'];

    foreach ($cardNames as $name) {
        $event = LogEvent::factory()->create([
            'event_type' => 'card_revealed',
            'match_token' => 'accum-tok',
            'raw_text' => json_encode([
                'player' => 'Opponent',
                'card_name' => $name,
                'action' => 'casts',
            ]),
        ]);

        DispatchDomainEvents::run(collect([$event]));
    }

    $cards = Cache::get('archetype_detect:accum-tok:cards');
    expect($cards)->toHaveCount(4);

    $version = Cache::get('archetype_detect:accum-tok:version');
    expect($version)->toBe(4);
});
