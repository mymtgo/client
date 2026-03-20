<?php

use App\Enums\MatchState;
use App\Events\MatchJoined;
use App\Listeners\Pipeline\CreateMatch;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a match from a join event using match_token', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'token-abc',
        'raw_text' => "10:00:00 [INF] (Match|MatchJoinedEventUnderwayState)\nReceiver: match\nPlayFormatCd=Modern\nGameStructureCd=BO3",
        'timestamp' => '14:30:00',
        'logged_at' => now(),
    ]);

    $listener = new CreateMatch;
    $listener->handle(new MatchJoined($event));

    $match = MtgoMatch::where('token', 'token-abc')->first();
    expect($match)->not->toBeNull();
    expect($match->token)->toBe('token-abc');
    expect($match->state)->toBe(MatchState::Started);
    expect($match->format)->toBe('Modern');
    expect($match->match_type)->toBe('BO3');
});

it('does nothing if match already exists for this token', function () {
    MtgoMatch::factory()->create([
        'token' => 'token-abc',
        'state' => MatchState::InProgress,
    ]);

    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'token-abc',
    ]);

    $listener = new CreateMatch;
    $listener->handle(new MatchJoined($event));

    expect(MtgoMatch::where('token', 'token-abc')->count())->toBe(1);
});

it('is idempotent — second call does not duplicate', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'token-abc',
        'raw_text' => "Receiver: match\nPlayFormatCd=Modern\nGameStructureCd=BO3",
        'timestamp' => '14:30:00',
        'logged_at' => now(),
    ]);

    $listener = new CreateMatch;
    $listener->handle(new MatchJoined($event));
    $listener->handle(new MatchJoined($event));

    expect(MtgoMatch::where('token', 'token-abc')->count())->toBe(1);
});

it('uses Unknown for missing metadata', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'token-xyz',
        'raw_text' => 'Match State Changed for token-xyz',
        'timestamp' => '10:00:00',
        'logged_at' => now(),
    ]);

    $listener = new CreateMatch;
    $listener->handle(new MatchJoined($event));

    $match = MtgoMatch::where('token', 'token-xyz')->first();
    expect($match->format)->toBe('Unknown');
    expect($match->match_type)->toBe('Unknown');
});
