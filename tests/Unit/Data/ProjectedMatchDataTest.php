<?php

use App\Data\ProjectedMatch\ProjectedMatchData;
use Tests\TestCase;

uses(TestCase::class);

function contractPayload(): array
{
    return [
        'schema_version' => 1,
        'client_version' => '1.0.0',
        'source' => 'mtgo',
        'match_key' => '95f4d09f-7d8f-4e14-aafd-1abed0415ea8',
        'compiled_at' => '2026-07-01T00:00:00Z',
        'file_version' => 1,
        'imported' => false,
        'mtgo_username' => 'Pro_MTG',
        'mtgo_player_id' => 147160,
        'match' => [
            'token' => '95f4d09f-7d8f-4e14-aafd-1abed0415ea8',
            'mtgo_id' => 285753048,
            'format' => 'CModern',
            'match_type' => 'League',
            'outcome' => 'Win',
            'outcome_source' => 'resolved',
            'state' => 'Complete',
            'started_at' => '2026-07-01T00:00:00Z',
            'ended_at' => '2026-07-01T00:10:00Z',
            'notes' => null,
            'opponent' => ['mtgo_player_id' => 3022021, 'username' => 'anticloser'],
            'deck' => [
                'mtgo_id' => 12345,
                'name' => 'Dimir Murktide',
                'format' => 'CModern',
                'color_identity' => 'UB',
                'modified_at' => '2026-06-30T12:00:00Z',
                'signature' => 'c2lnbmF0dXJl',
            ],
            'league' => [
                'token' => '0e46e574-aaaa-bbbb-cccc-111111111111',
                'name' => 'Modern League',
                'format' => 'CModern',
                'joined_at' => '2026-06-30T23:00:00Z',
                'dropped_at' => null,
            ],
            'tournament' => null,
            'games' => [
                [
                    'mtgo_id' => 954965154,
                    'won' => true,
                    'started_at' => '2026-07-01T00:00:30Z',
                    'ended_at' => '2026-07-01T00:05:00Z',
                    'turn_count' => 9,
                    'local_on_play' => true,
                    'local_mulligans' => 1,
                    'opp_mulligans' => 0,
                    'local_dice' => 6,
                    'opp_dice' => 3,
                    'local_instance' => 111,
                    'opp_instance' => 222,
                    'local_deck' => ['signature' => 'c2lnbmF0dXJl'],
                    'opponent_deck' => ['signature' => 'b3Bwc2ln'],
                    'card_stats' => [
                        [
                            'oracle_id' => 'aa7714f4-9ed4-4d14-9a24-91e3d1f4c235',
                            'opponent' => false,
                            'quantity' => 4,
                            'kept' => 1,
                            'seen' => 2,
                            'played' => 1,
                            'won' => true,
                            'is_postboard' => false,
                            'sided_out' => false,
                            'pregame_revealed' => false,
                            'pregame_played' => false,
                            'kicked' => 0,
                            'flashback' => 0,
                            'madness' => 0,
                            'evoked' => 0,
                            'activated' => 0,
                        ],
                    ],
                    'timeline' => [
                        ['action' => 'roll', 'timestamp' => '2026-07-01T00:00:31Z', 'player' => 'Pro_MTG', 'context' => 'rolled 6'],
                    ],
                ],
            ],
            'opponent_archetype' => ['uuid' => 'b1e0c2d3-0000-0000-0000-000000000001', 'name' => 'Burn', 'confidence' => 0.82],
        ],
    ];
}

it('round-trips the full {match}.json contract shape', function () {
    $payload = contractPayload();

    $json = ProjectedMatchData::from($payload)->toArray();

    expect($json)->toEqual($payload);
});

it('keys the match by its token', function () {
    $json = ProjectedMatchData::from(contractPayload())->toArray();

    expect($json['match_key'])->toBe($json['match']['token']);
});

it('serializes outcome enums to the contract strings', function () {
    $json = ProjectedMatchData::from(contractPayload())->toArray();

    expect($json['match']['outcome'])->toBe('Win');
    expect($json['match']['outcome_source'])->toBe('resolved');
});

it('accepts the sparse 0.x-import variant', function () {
    $payload = contractPayload();
    $payload['imported'] = true;
    $payload['mtgo_player_id'] = null;
    $payload['match']['games'] = [];
    $payload['match']['deck'] = null;
    $payload['match']['league'] = null;
    $payload['match']['opponent_archetype'] = null;
    $payload['match']['opponent'] = ['mtgo_player_id' => null, 'username' => 'anticloser'];

    $json = ProjectedMatchData::from($payload)->toArray();

    expect($json)->toEqual($payload);
    expect($json['match']['games'])->toBe([]);
});

it('allows null won on abandoned games', function () {
    $payload = contractPayload();
    $payload['match']['games'][0]['won'] = null;

    $json = ProjectedMatchData::from($payload)->toArray();

    expect($json['match']['games'][0]['won'])->toBeNull();
});
