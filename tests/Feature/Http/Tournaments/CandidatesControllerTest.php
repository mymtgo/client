<?php

use App\Enums\MatchState;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns participated tournaments matching the match type within ±12h', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'format' => 'CMODERN',
        'started_at' => now(),
    ]);

    $nearby = Tournament::factory()->create([
        'type' => TournamentType::Constructed,
        'participated' => true,
        'started_at' => now()->subHours(2),
    ]);
    Tournament::factory()->create([
        'type' => TournamentType::Constructed,
        'participated' => true,
        'started_at' => now()->subDays(3),
    ]);
    Tournament::factory()->create([
        'type' => TournamentType::Limited,
        'participated' => true,
        'started_at' => now(),
    ]);
    Tournament::factory()->create([
        'type' => TournamentType::Constructed,
        'participated' => false,
        'started_at' => now(),
    ]);

    $response = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id]));

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment(['id' => $nearby->id]);
});

it('returns all participated tournaments when all=1', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'format' => 'CMODERN',
        'started_at' => now(),
    ]);

    Tournament::factory()->count(3)->create(['participated' => true]);
    Tournament::factory()->create(['participated' => false]);

    $response = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id, 'all' => 1]));

    $response->assertOk();
    $response->assertJsonCount(3);
});

it('returns an empty default list when the match format has no tournament type mapping', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'format' => 'Xunknown',
        'started_at' => now(),
    ]);

    Tournament::factory()->count(2)->create(['participated' => true, 'started_at' => now()]);

    $defaultResponse = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id]));
    $defaultResponse->assertOk();
    $defaultResponse->assertJsonCount(0);

    $allResponse = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id, 'all' => 1]));
    $allResponse->assertOk();
    $allResponse->assertJsonCount(2);
});
