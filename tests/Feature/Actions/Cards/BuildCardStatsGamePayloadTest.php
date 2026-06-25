<?php

use App\Actions\Cards\BuildCardStatsGamePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\CardStatsTelemetryFactory;

uses(RefreshDatabase::class);

it('builds payload with API-required fields', function () {
    $scaffold = CardStatsTelemetryFactory::make();
    $game = $scaffold['games'][0]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);

    $payload = BuildCardStatsGamePayload::run($game);

    expect($payload)->not->toBeNull()
        ->toHaveKeys(['player_archetype_uuid', 'opponent_archetype_uuid', 'format', 'won', 'on_play', 'is_postboard', 'played_on', 'cards']);

    expect($payload['player_archetype_uuid'])->toBe($scaffold['archetype']->uuid);
    expect($payload['opponent_archetype_uuid'])->toBe($scaffold['opponentArchetype']->uuid);
    expect($payload['format'])->toBe('CStandard');
    expect($payload['won'])->toBeTrue();
    expect($payload['on_play'])->toBeTrue();
    expect($payload['is_postboard'])->toBeFalse();
    expect($payload['played_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($payload['cards'])->toHaveCount(1);
});

it('filters opponent card rows from payload', function () {
    $scaffold = CardStatsTelemetryFactory::make(games: [[
        'cards' => [
            ['oracle_id' => 'player-card', 'opponent' => false],
            ['oracle_id' => 'opp-card', 'opponent' => true],
        ],
    ]]);

    $game = $scaffold['games'][0]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);

    $payload = BuildCardStatsGamePayload::run($game);

    expect($payload['cards'])->toHaveCount(1);
    expect($payload['cards'][0]['oracle_id'])->toBe('player-card');
});

it('derives is_postboard from game order within match', function () {
    $scaffold = CardStatsTelemetryFactory::make(games: [
        ['won' => true, 'started_at' => now(), 'cards' => [[]]],
        ['won' => false, 'started_at' => now()->addMinutes(10), 'cards' => [[]]],
    ]);

    $game1 = $scaffold['games'][0]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);
    $game2 = $scaffold['games'][1]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);

    expect(BuildCardStatsGamePayload::run($game1)['is_postboard'])->toBeFalse();
    expect(BuildCardStatsGamePayload::run($game2)['is_postboard'])->toBeTrue();
});

it('returns null when player archetype is missing', function () {
    $scaffold = CardStatsTelemetryFactory::make(withLocalArchetype: false);
    $game = $scaffold['games'][0]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);

    expect(BuildCardStatsGamePayload::run($game))->toBeNull();
});

it('returns null when no local player attached', function () {
    $scaffold = CardStatsTelemetryFactory::make();
    $game = $scaffold['games'][0];
    $game->update(['local_instance' => null]);
    $game = $game->fresh()->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);

    expect(BuildCardStatsGamePayload::run($game))->toBeNull();
});

it('returns null when all card rows are opponent-side', function () {
    $scaffold = CardStatsTelemetryFactory::make(games: [[
        'cards' => [['opponent' => true]],
    ]]);
    $game = $scaffold['games'][0]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);

    expect(BuildCardStatsGamePayload::run($game))->toBeNull();
});

it('returns null opponent_archetype_uuid when opponent archetype missing', function () {
    $scaffold = CardStatsTelemetryFactory::make(withOpponentArchetype: false);
    $game = $scaffold['games'][0]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);

    $payload = BuildCardStatsGamePayload::run($game);

    expect($payload)->not->toBeNull();
    expect($payload['opponent_archetype_uuid'])->toBeNull();
});

it('serializes card field types correctly', function () {
    $oracleId = (string) Str::uuid();
    $scaffold = CardStatsTelemetryFactory::make(games: [[
        'cards' => [[
            'oracle_id' => $oracleId,
            'quantity' => 3,
            'kept' => 1,
            'seen' => 2,
            'cast' => 1,
            'played' => 1,
            'activated' => 5,
            'pregame_revealed' => true,
            'pregame_played' => false,
            'sided_in' => true,
            'sided_out' => false,
        ]],
    ]]);

    $game = $scaffold['games'][0]->load(['match.games', 'match.archetypes.archetype', 'match.opponentArchetypes.archetype', 'decks', 'cardGameStats']);
    $payload = BuildCardStatsGamePayload::run($game);
    $card = $payload['cards'][0];

    expect($card['oracle_id'])->toBe($oracleId);
    expect($card['quantity'])->toBe(3);
    expect($card['activated'])->toBe(5);
    expect($card['pregame_revealed'])->toBeTrue();
    expect($card['pregame_played'])->toBeFalse();
    expect($card['sided_in'])->toBeTrue();
    expect($card['sided_out'])->toBeFalse();
});
