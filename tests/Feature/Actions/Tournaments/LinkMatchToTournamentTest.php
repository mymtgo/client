<?php

use App\Actions\Tournaments\LinkMatchToTournament;
use App\Enums\MatchState;
use App\Enums\TournamentState;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a stub tournament when event_id is unknown', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Started,
        'format' => 'CMODERN',
    ]);

    $gameMeta = [
        'Description' => 'Tournament:12839688 Round:1',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
        'PlayerIds' => '964394,2714690',
    ];

    LinkMatchToTournament::run($match, $gameMeta);

    $tournament = Tournament::where('event_id', 12839688)->firstOrFail();

    expect($tournament->type)->toBe(TournamentType::Constructed);
    expect($tournament->format)->toBe('Modern');
    expect($tournament->participated)->toBeTrue();
    expect($tournament->state)->toBe(TournamentState::RoundInProgress);

    $match->refresh();
    expect($match->tournament_id)->toBe($tournament->id);
    expect($match->tournament_round)->toBe(1);
    expect($match->participant_login_ids)->toBe([964394, 2714690]);
});

it('links to an existing tournament without overwriting fields', function () {
    $tournament = Tournament::factory()->create([
        'event_id' => 12839688,
        'name' => 'Modern Challenge',
        'type' => TournamentType::Constructed,
        'state' => TournamentState::RoundInProgress,
        'participated' => false,
    ]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);

    LinkMatchToTournament::run($match, [
        'Description' => 'Tournament:12839688 Round:3',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
        'PlayerIds' => '964394,2888604',
    ]);

    $tournament->refresh();
    expect($tournament->name)->toBe('Modern Challenge');
    expect($tournament->participated)->toBeTrue();

    $match->refresh();
    expect($match->tournament_id)->toBe($tournament->id);
    expect($match->tournament_round)->toBe(3);
});

it('is idempotent when run twice', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);

    $gameMeta = [
        'Description' => 'Tournament:12839688 Round:1',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
        'PlayerIds' => '964394,2714690',
    ];

    LinkMatchToTournament::run($match, $gameMeta);
    LinkMatchToTournament::run($match, $gameMeta);

    expect(Tournament::where('event_id', 12839688)->count())->toBe(1);

    $match->refresh();
    expect($match->tournament_round)->toBe(1);
});

it('exits silently when Description lacks a Tournament token', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);

    LinkMatchToTournament::run($match, [
        'Description' => 'League',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
    ]);

    $match->refresh();
    expect($match->tournament_id)->toBeNull();
    expect(Tournament::count())->toBe(0);
});
