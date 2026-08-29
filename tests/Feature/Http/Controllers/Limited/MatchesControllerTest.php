<?php

use App\Enums\LeagueKind;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 for a constructed league', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed]);
    $this->get(route('limited.matches', ['league' => $league->id]))->assertNotFound();
});

it('renders league matches with kpis', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()->subMinutes(50), 'ended_at' => now()->subMinutes(40)]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss, 'started_at' => now()->subMinutes(30), 'ended_at' => now()->subMinutes(18)]);
    MtgoMatch::factory()->create(['state' => MatchState::Complete, 'outcome' => MatchOutcome::Win]);

    $this->get(route('limited.matches', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('limited/Matches')
            ->where('currentPage', 'matches')
            ->has('matches', 2)
            ->where('kpis.wins', 1)
            ->where('kpis.losses', 1)
            ->where('kpis.totalMinutes', 32)
        );
});

it('renders an empty matches page when the league has no complete matches', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'started_at' => now()]);

    $this->get(route('limited.matches', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('limited/Matches')
            ->has('matches', 0)
            ->where('kpis.wins', 0)
            ->where('kpis.losses', 0)
            ->where('kpis.totalMinutes', null)
        );
});

it('labels a draft opponent with the colours they revealed', function () {
    Card::factory()->create(['mtgo_id' => 8001, 'color_identity' => 'B']);
    Card::factory()->create(['mtgo_id' => 8002, 'color_identity' => 'G']);

    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    $match = MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'started_at' => now()->subMinutes(50),
    ]);

    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach(Player::factory()->create()->id, ['instance_id' => 2, 'is_local' => true, 'deck_json' => []]);
    $game->players()->attach(Player::factory()->create()->id, [
        'instance_id' => 1,
        'is_local' => false,
        'deck_json' => [
            ['mtgo_id' => 8001, 'quantity' => 1],
            ['mtgo_id' => 8002, 'quantity' => 2],
        ],
    ]);

    $this->get(route('limited.matches', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('matches.0.opponentColors', 'B,G'));
});

it('leaves opponent colours null when nothing colourful was revealed', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
        'started_at' => now()->subMinutes(50),
    ]);

    $this->get(route('limited.matches', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('matches.0.opponentColors', null));
});
