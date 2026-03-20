<?php

use App\Enums\MatchState;
use App\Events\MatchMetadataReceived;
use App\Listeners\Pipeline\StoreMatchMetadata;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

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

it('backfills format and match_type from the Receiver key-value block', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-format-backfill',
        'mtgo_id' => '88888',
        'format' => 'Unknown',
        'match_type' => 'Unknown',
        'state' => MatchState::InProgress,
    ]);

    $rawText = "19:44:10 [INF] (Game Management|Processing Registered Handler) Message: {\"MatchToken\":\"token-format-backfill\",\"MatchID\":88888,\"MetaMessage\":[1,2,3]} Receiver: Event Token=token-format-backfill\r\nPlayFormatCd=CMODERN\r\nGameStructureCd= Modern\r\nJoinedToGame=True";

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-format-backfill',
        'match_id' => '88888',
        'event_type' => 'game_management_json',
        'raw_text' => $rawText,
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    $match->refresh();
    expect($match->format)->toBe('CMODERN')
        // Leading space after = is consumed by ExtractKeyValueBlock regex
        ->and($match->match_type)->toBe('Modern');
});

it('assigns a phantom league when metadata is available and no league set', function () {
    Settings::shouldReceive('get')
        ->with('hide_phantom_leagues')
        ->andReturn(false);

    $match = MtgoMatch::factory()->create([
        'token' => 'token-phantom-league',
        'mtgo_id' => '66666',
        'format' => 'CMODERN',
        'match_type' => 'Modern',
        'state' => MatchState::InProgress,
        'league_id' => null,
    ]);

    $rawText = "19:44:10 [INF] (Game Management|Processing) Message: {\"MatchToken\":\"token-phantom-league\",\"MatchID\":66666} Receiver: Event Token=token-phantom-league\r\nPlayFormatCd=CMODERN\r\nGameStructureCd= Modern\r\nJoinedToGame=True";

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-phantom-league',
        'match_id' => '66666',
        'event_type' => 'game_management_json',
        'raw_text' => $rawText,
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    $match->refresh();
    expect($match->league_id)->not->toBeNull();
});

it('does not overwrite existing format when not Unknown', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-keep-format',
        'mtgo_id' => '77777',
        'format' => 'CLEGACY',
        'match_type' => 'Legacy',
        'state' => MatchState::InProgress,
    ]);

    $rawText = "19:44:10 [INF] (Game Management|Processing) Message: {\"MatchToken\":\"token-keep-format\",\"MatchID\":77777} Receiver: Event Token=token-keep-format\r\nPlayFormatCd=CMODERN\r\nGameStructureCd=Modern";

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-keep-format',
        'match_id' => '77777',
        'event_type' => 'game_management_json',
        'raw_text' => $rawText,
        'processed_at' => null,
    ]);

    $listener = new StoreMatchMetadata;
    $listener->handle(new MatchMetadataReceived($logEvent));

    $match->refresh();
    expect($match->format)->toBe('CLEGACY')
        ->and($match->match_type)->toBe('Legacy');
});
