<?php

use App\Events\CardRevealed;
use App\Jobs\EstimateArchetypeJob;
use App\Listeners\Pipeline\DetectArchetype;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('accumulates cards in cache and dispatches EstimateArchetypeJob', function () {
    Queue::fake();

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-1',
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['card_name' => 'Lightning Bolt', 'player' => 'Opponent']),
        'processed_at' => null,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new CardRevealed($logEvent));

    $cards = Cache::get('archetype_detect:token-detect-1:cards');
    expect($cards)->toHaveCount(1);
    expect($cards[0]['card_name'])->toBe('Lightning Bolt');
    expect($cards[0]['quantity'])->toBe(1);
    expect($cards[0]['player'])->toBe('Opponent');

    Queue::assertPushed(EstimateArchetypeJob::class, function ($job) {
        return $job->matchToken === 'token-detect-1' && $job->version === 1;
    });
});

it('increments quantity for duplicate cards up to a maximum of 4', function () {
    Queue::fake();

    $token = 'token-detect-2';
    $cacheKey = "archetype_detect:{$token}:cards";

    // Pre-seed cache with 3 copies already seen
    Cache::put($cacheKey, [
        ['card_name' => 'Lightning Bolt', 'quantity' => 3, 'player' => 'Opponent'],
    ], now()->addHour());
    Cache::put("archetype_detect:{$token}:version", 3, now()->addHour());

    $logEvent = LogEvent::factory()->create([
        'match_token' => $token,
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['card_name' => 'Lightning Bolt', 'player' => 'Opponent']),
        'processed_at' => null,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new CardRevealed($logEvent));

    $cards = Cache::get($cacheKey);
    expect($cards[0]['quantity'])->toBe(4);

    // Trigger again — should cap at 4
    $logEvent2 = LogEvent::factory()->create([
        'match_token' => $token,
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['card_name' => 'Lightning Bolt', 'player' => 'Opponent']),
        'processed_at' => null,
    ]);

    $listener->handle(new CardRevealed($logEvent2));

    $cards = Cache::get($cacheKey);
    expect($cards[0]['quantity'])->toBe(4); // Still capped at 4
});

it('accumulates different cards separately', function () {
    Queue::fake();

    $token = 'token-detect-3';

    $logEvent1 = LogEvent::factory()->create([
        'match_token' => $token,
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['card_name' => 'Lightning Bolt', 'player' => 'Opponent']),
        'processed_at' => null,
    ]);

    $logEvent2 = LogEvent::factory()->create([
        'match_token' => $token,
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['card_name' => 'Goblin Guide', 'player' => 'Opponent']),
        'processed_at' => null,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new CardRevealed($logEvent1));
    $listener->handle(new CardRevealed($logEvent2));

    $cards = Cache::get("archetype_detect:{$token}:cards");
    expect($cards)->toHaveCount(2);

    $cardNames = array_column($cards, 'card_name');
    expect($cardNames)->toContain('Lightning Bolt');
    expect($cardNames)->toContain('Goblin Guide');
});

it('does nothing for events without a match_token', function () {
    Queue::fake();

    $logEvent = LogEvent::factory()->create([
        'match_token' => null,
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['card_name' => 'Lightning Bolt', 'player' => 'Opponent']),
        'processed_at' => null,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new CardRevealed($logEvent));

    Queue::assertNotPushed(EstimateArchetypeJob::class);
    expect(Cache::get('archetype_detect::cards'))->toBeNull();
});

it('marks the LogEvent as processed', function () {
    Queue::fake();

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-5',
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['card_name' => 'Thoughtseize', 'player' => 'Opponent']),
        'processed_at' => null,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new CardRevealed($logEvent));

    $logEvent->refresh();
    expect($logEvent->processed_at)->not->toBeNull();
});

it('does nothing when raw_text is missing required fields', function () {
    Queue::fake();

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-detect-6',
        'event_type' => 'card_revealed',
        'raw_text' => json_encode(['only_card_name' => 'Lightning Bolt']), // missing 'player'
        'processed_at' => null,
    ]);

    $listener = new DetectArchetype;
    $listener->handle(new CardRevealed($logEvent));

    Queue::assertNotPushed(EstimateArchetypeJob::class);
    expect(Cache::get('archetype_detect:token-detect-6:cards'))->toBeNull();
});

it('increments version counter on each card reveal', function () {
    Queue::fake();

    $token = 'token-detect-7';
    $versionKey = "archetype_detect:{$token}:version";

    $listener = new DetectArchetype;

    foreach (['CardA', 'CardB', 'CardC'] as $cardName) {
        $logEvent = LogEvent::factory()->create([
            'match_token' => $token,
            'event_type' => 'card_revealed',
            'raw_text' => json_encode(['card_name' => $cardName, 'player' => 'Opp']),
            'processed_at' => null,
        ]);

        $listener->handle(new CardRevealed($logEvent));
    }

    expect(Cache::get($versionKey))->toBe(3);

    Queue::assertPushed(EstimateArchetypeJob::class, 3);
});
