<?php

use App\Events\CardRevealed;
use App\Events\DeckUsedInGame;
use App\Events\GameResultDetermined;
use App\Events\GameStateChanged;
use App\Events\LeagueJoined;
use App\Events\LeagueJoinRequested;
use App\Events\MatchEnded;
use App\Events\MatchJoined;
use App\Events\MatchMetadataReceived;
use App\Events\UserLoggedIn;
use App\Models\LogEvent;

dataset('domain events', [
    'MatchJoined' => [MatchJoined::class],
    'MatchEnded' => [MatchEnded::class],
    'GameStateChanged' => [GameStateChanged::class],
    'GameResultDetermined' => [GameResultDetermined::class],
    'DeckUsedInGame' => [DeckUsedInGame::class],
    'CardRevealed' => [CardRevealed::class],
    'LeagueJoinRequested' => [LeagueJoinRequested::class],
    'LeagueJoined' => [LeagueJoined::class],
    'MatchMetadataReceived' => [MatchMetadataReceived::class],
    'UserLoggedIn' => [UserLoggedIn::class],
]);

it('can be constructed with a LogEvent model', function (string $eventClass) {
    $logEvent = LogEvent::factory()->make();

    $event = new $eventClass($logEvent);

    expect($event)->toBeInstanceOf($eventClass);
    expect($event->logEvent)->toBe($logEvent);
})->with('domain events');
