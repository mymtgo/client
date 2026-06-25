<?php

use App\Data\Front\GameResultSummaryData;
use App\Data\Front\MatchArchetypeData;
use App\Data\Front\MatchData;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes opponentName from the opponent relation, not games', function () {
    $opponent = Opponent::factory()->create(['username' => 'SomePlayer']);
    $match = MtgoMatch::factory()->create(['opponent_id' => $opponent->id]);

    $match->load('opponent');

    $data = MatchData::from($match);

    expect($data->opponentName->resolve())->toBe('SomePlayer');
});

it('exposes null opponentName when opponent is not set', function () {
    $match = MtgoMatch::factory()->create(['opponent_id' => null]);

    $match->load('opponent');

    $data = MatchData::from($match);

    expect($data->opponentName->resolve())->toBeNull();
});

it('exposes opponentArchetypes from the opponentArchetypes relation', function () {
    $opponent = Opponent::factory()->create();
    $match = MtgoMatch::factory()->create(['opponent_id' => $opponent->id]);
    $archetype = Archetype::factory()->create();

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'confidence' => 0.9,
        'is_opponent' => true,
    ]);

    $match->load(['opponent', 'opponentArchetypes']);

    $data = MatchData::from($match);

    $collected = $data->opponentArchetypes->resolve();
    expect($collected)->toHaveCount(1);
    expect($collected->first())->toBeInstanceOf(MatchArchetypeData::class);
    expect($collected->first()->confidence)->toBe(0.9);
});

it('does not include local archetypes in opponentArchetypes', function () {
    $opponent = Opponent::factory()->create();
    $match = MtgoMatch::factory()->create(['opponent_id' => $opponent->id]);
    $archetype = Archetype::factory()->create();

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'confidence' => 0.8,
        'is_opponent' => false,
    ]);

    $match->load(['opponent', 'opponentArchetypes']);

    $data = MatchData::from($match);

    $collected = $data->opponentArchetypes->resolve();
    expect($collected)->toHaveCount(0);
});

it('builds gameResults with onPlay from local_on_play scalar column', function () {
    $match = MtgoMatch::factory()->create();

    Game::factory()->create([
        'match_id' => $match->id,
        'won' => true,
        'local_on_play' => true,
        'started_at' => now()->subMinutes(10),
    ]);
    Game::factory()->create([
        'match_id' => $match->id,
        'won' => false,
        'local_on_play' => false,
        'started_at' => now()->subMinutes(5),
    ]);

    $match->load('games');

    $data = MatchData::from($match);

    $results = $data->gameResults->resolve();
    expect($results)->toHaveCount(2);

    expect($results[0])->toBeInstanceOf(GameResultSummaryData::class);
    expect($results[0]->result)->toBe('W');
    expect($results[0]->onPlay)->toBeTrue();

    expect($results[1]->result)->toBe('L');
    expect($results[1]->onPlay)->toBeFalse();
});

it('gameResults onPlay is null when local_on_play is null', function () {
    $match = MtgoMatch::factory()->create();

    Game::factory()->create([
        'match_id' => $match->id,
        'won' => true,
        'local_on_play' => null,
        'started_at' => now(),
    ]);

    $match->load('games');

    $data = MatchData::from($match);
    $results = $data->gameResults->resolve();

    expect($results[0]->onPlay)->toBeNull();
});
