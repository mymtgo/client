<?php

use App\Enums\MatchState;
use App\Events\MatchMetadataReceived;
use App\Listeners\Pipeline\StoreMatchMetadata;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills mtgo_id on a match that was created without one', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-backfill',
        'mtgo_id' => null,
        'state' => MatchState::InProgress,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-backfill',
        'match_id' => '99999',
        'event_type' => 'game_management_json',
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    $match->refresh();
    expect($match->mtgo_id)->toBe('99999');

    $logEvent->refresh();
    expect($logEvent->processed_at)->not->toBeNull();
});

it('does nothing if match does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'nonexistent-token',
        'match_id' => '12345',
        'event_type' => 'game_management_json',
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    expect(MtgoMatch::where('token', 'nonexistent-token')->exists())->toBeFalse();
    // processed_at not set since there was nothing to process
    $logEvent->refresh();
    expect($logEvent->processed_at)->toBeNull();
});

it('does not overwrite an existing mtgo_id', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-existing-id',
        'mtgo_id' => '11111',
        'state' => MatchState::InProgress,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-existing-id',
        'match_id' => '22222',
        'event_type' => 'game_management_json',
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    $match->refresh();
    // Original mtgo_id should be preserved
    expect($match->mtgo_id)->toBe('11111');

    // Event still marked as processed
    $logEvent->refresh();
    expect($logEvent->processed_at)->not->toBeNull();
});

it('does nothing if log event has no match_token', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-no-token-event',
        'mtgo_id' => null,
        'state' => MatchState::InProgress,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => null,
        'match_id' => '55555',
        'event_type' => 'game_management_json',
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    $match->refresh();
    expect($match->mtgo_id)->toBeNull();
    $logEvent->refresh();
    expect($logEvent->processed_at)->toBeNull();
});

it('does nothing if log event has no match_id', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-no-match-id',
        'mtgo_id' => null,
        'state' => MatchState::InProgress,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-no-match-id',
        'match_id' => null,
        'event_type' => 'game_management_json',
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    $match->refresh();
    expect($match->mtgo_id)->toBeNull();
});
