<?php

use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $deck = Deck::factory()->create();
    $this->dv = DeckVersion::factory()->for($deck)->create();
});

function feedLeague(DeckVersion $dv, LeagueState $state, int $wins, int $losses, string $format = 'Modern'): League
{
    $league = League::factory()->for($dv)->create(['state' => $state, 'format' => $format]);
    $outcomes = array_merge(array_fill(0, $wins, MatchOutcome::Win), array_fill(0, $losses, MatchOutcome::Loss));

    foreach ($outcomes as $i => $o) {
        MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $dv->id,
            'state' => MatchState::Complete,
            'outcome' => $o,
            'format' => $format,
            'started_at' => now()->subMinutes(60 - $i),
            'ended_at' => now()->subMinutes(50 - $i),
        ]);
    }

    return $league;
}

it('returns kpis payload', function () {
    feedLeague($this->dv, LeagueState::Complete, 5, 0);

    $this->get('/leagues')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p
            ->has('kpis.runs.total')
            ->has('kpis.trophies')
            ->has('kpis.trophyRate')
            ->has('kpis.cashRate')
            ->has('kpis.avgFinish')
            ->has('kpis.topMatchup')
            ->etc()
        );
});

it('filters by trophies chip', function () {
    feedLeague($this->dv, LeagueState::Complete, 5, 0);
    feedLeague($this->dv, LeagueState::Complete, 3, 2);

    $this->get('/leagues?state=trophies')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 1)->etc());
});

it('filters by cash chip (4-1+)', function () {
    feedLeague($this->dv, LeagueState::Complete, 5, 0);
    feedLeague($this->dv, LeagueState::Complete, 4, 1);
    feedLeague($this->dv, LeagueState::Complete, 3, 2);

    $this->get('/leagues?state=cash')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 2)->etc());
});

it('filters by finish chip', function () {
    feedLeague($this->dv, LeagueState::Complete, 5, 0);
    feedLeague($this->dv, LeagueState::Complete, 4, 1);
    feedLeague($this->dv, LeagueState::Complete, 3, 2);
    feedLeague($this->dv, LeagueState::Complete, 2, 3);

    $this->get('/leagues?state=finish')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 2)->etc());
});

it('filters by bricks chip', function () {
    feedLeague($this->dv, LeagueState::Dropped, 1, 3);
    feedLeague($this->dv, LeagueState::Complete, 5, 0);

    $this->get('/leagues?state=bricks')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 1)->etc());
});

it('filters by live chip', function () {
    feedLeague($this->dv, LeagueState::Active, 2, 0);
    feedLeague($this->dv, LeagueState::Complete, 5, 0);

    $this->get('/leagues?state=live')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 1)->etc());
});

it('filters by deck', function () {
    $deck2 = Deck::factory()->create();
    $dv2 = DeckVersion::factory()->for($deck2)->create();

    feedLeague($this->dv, LeagueState::Complete, 5, 0);
    feedLeague($dv2, LeagueState::Complete, 4, 1);

    $this->get('/leagues?deck='.$deck2->id)
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 1)->etc());
});

it('filters by deck archetype', function () {
    $archA = Archetype::factory()->create(['name' => 'Yawgmoth']);
    $archB = Archetype::factory()->create(['name' => 'Burn']);

    $deckA = Deck::factory()->create(['archetype_id' => $archA->id]);
    $dvA = DeckVersion::factory()->for($deckA)->create();
    $deckB = Deck::factory()->create(['archetype_id' => $archB->id]);
    $dvB = DeckVersion::factory()->for($deckB)->create();

    feedLeague($dvA, LeagueState::Complete, 5, 0);
    feedLeague($dvB, LeagueState::Complete, 4, 1);

    $this->get('/leagues?archetype='.$archA->id)
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 1)->etc());
});

it('exposes deckArchetypes for the filter dropdown', function () {
    $arch = Archetype::factory()->create(['name' => 'Yawgmoth']);
    $deck = Deck::factory()->create(['archetype_id' => $arch->id]);
    $dv = DeckVersion::factory()->for($deck)->create();

    feedLeague($dv, LeagueState::Complete, 5, 0);

    $this->get('/leagues')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('deckArchetypes')->etc());
});

it('filters by opponent username search', function () {
    $league1 = feedLeague($this->dv, LeagueState::Complete, 5, 0);
    feedLeague($this->dv, LeagueState::Complete, 4, 1);

    $opponent = Opponent::factory()->create(['username' => 'targetuser']);
    $match = $league1->matches->first();
    $match->update(['opponent_id' => $opponent->id]);

    $this->get('/leagues?q=target')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 1)->etc());
});

it('filters by archetype search', function () {
    $league1 = feedLeague($this->dv, LeagueState::Complete, 5, 0);
    feedLeague($this->dv, LeagueState::Complete, 4, 1);

    $arch = Archetype::factory()->create(['name' => 'Yawgmoth']);
    $match = $league1->matches->first();

    DB::table('match_archetypes')->insert([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $arch->id,
        'is_opponent' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get('/leagues?q=Yawgmoth')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('leagues.data', 1)->etc());
});

it('sorts by best (wins desc)', function () {
    $a = feedLeague($this->dv, LeagueState::Complete, 3, 2);
    $b = feedLeague($this->dv, LeagueState::Complete, 5, 0);

    $this->get('/leagues?sort=best')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p
            ->where('leagues.data.0.id', $b->id)
            ->where('leagues.data.1.id', $a->id)
            ->etc()
        );
});

it('exposes allDecks list', function () {
    feedLeague($this->dv, LeagueState::Complete, 5, 0);

    $this->get('/leagues')
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p->has('allDecks')->etc());
});
