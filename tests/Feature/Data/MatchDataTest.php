<?php

use App\Data\Front\MatchData;
use App\Enums\MatchState;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes tournament info when the match is linked', function () {
    $tournament = Tournament::factory()->create([
        'event_id' => 12345678,
        'type' => TournamentType::Constructed,
        'format' => 'Modern',
        'participated' => true,
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 4,
    ]);

    $match->load('tournament');

    $data = MatchData::from($match)->include('tournament')->toArray();

    expect($data['tournament'])->toMatchArray([
        'id' => $tournament->id,
        'eventId' => 12345678,
        'format' => 'Modern',
    ]);
    expect($data['tournamentRound'])->toBe(4);
});

it('omits tournament info when not linked', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
    ]);

    $match->load('tournament');

    $data = MatchData::from($match)->include('tournament')->toArray();

    expect($data['tournament'])->toBeNull();
    expect($data['tournamentRound'])->toBeNull();
});
