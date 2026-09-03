<?php

use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns deck-scoped kpis', function () {
    $deck = Deck::factory()->create();
    $dv = DeckVersion::factory()->for($deck)->create();

    $league = League::factory()->for($dv)->create(['state' => LeagueState::Complete, 'format' => 'Modern']);
    foreach (range(1, 5) as $i) {
        MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $dv->id,
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'format' => 'Modern',
            'started_at' => now()->subMinutes($i),
            'ended_at' => now()->subMinutes($i),
        ]);
    }

    $this->get("/decks/{$deck->id}/leagues")
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p
            ->has('kpis.runs.total')
            ->has('kpis.trophies')
            ->etc()
        );
});

it('does not embed base64 cover art in league runs', function () {
    $cover = Card::factory()->create([
        'art_crop' => 'https://cards.example.test/art/abc.jpg',
        'local_art_crop' => null,
    ]);
    $deck = Deck::factory()->create(['cover_id' => $cover->id]);
    $dv = DeckVersion::factory()->for($deck)->create();
    $league = League::factory()->for($dv)->create(['state' => LeagueState::Complete, 'format' => 'Modern']);
    MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'deck_version_id' => $dv->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'format' => 'Modern',
        'started_at' => now()->subMinutes(5),
        'ended_at' => now()->subMinutes(1),
    ]);

    $this->get("/decks/{$deck->id}/leagues")
        ->assertSuccessful()
        ->assertInertia(fn ($p) => $p
            ->where('leagues.0.deck.coverArt', 'https://cards.example.test/art/abc.jpg')
            ->missing('leagues.0.deck.coverArtBase64')
        );
});

it('does not run a decklist-existence query per archetype option', function () {
    $deck = Deck::factory()->create();
    $withDecklist = Archetype::factory()->create(['name' => 'Aaa Has Decks']);
    ArchetypeDeck::factory()->for($withDecklist)->create();
    Archetype::factory()->create(['name' => 'Zzz No Decks']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $archetypes = inertiaPartial("/decks/{$deck->id}/leagues", 'decks/Leagues', ['archetypes'])
        ->assertOk()
        ->json('props.archetypes');

    expect(collect($archetypes)->pluck('hasDecklist', 'name')->only(['Aaa Has Decks', 'Zzz No Decks'])->all())
        ->toBe(['Aaa Has Decks' => true, 'Zzz No Decks' => false]);

    $probes = collect(DB::getQueryLog())
        ->filter(fn (array $q) => str_starts_with($q['query'], 'select exists'))
        ->count();

    expect($probes)->toBe(0);
});
