<?php

use App\Enums\MatchOutcome;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCompletedMatch(
    DeckVersion $deckVersion,
    ?Archetype $opponentArchetype,
    MatchOutcome $outcome,
    bool $won,
    ?int $leagueId = null,
): MtgoMatch {
    $opp = Opponent::create(['username' => 'opp_'.fake()->uuid()]);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'outcome' => $outcome,
        'started_at' => now(),
        'league_id' => $leagueId,
        'opponent_id' => $opp->id,
    ]);

    if ($opponentArchetype) {
        MatchArchetype::create([
            'mtgo_match_id' => $match->id,
            'archetype_id' => $opponentArchetype->id,
            'is_opponent' => true,
        ]);
    }

    Game::factory()->create([
        'match_id' => $match->id,
        'won' => $won,
        'turn_count' => 8,
        'local_on_play' => true,
        'local_mulligans' => 0,
        'opp_mulligans' => 1,
        'local_instance' => fake()->randomNumber(6),
        'opp_instance' => fake()->randomNumber(6),
    ]);

    return $match;
}

it('renders the game stats page with stats rows and opponent options', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create(['name' => 'Eldrazi Ramp']);

    createCompletedMatch($deckVersion, $archetype, MatchOutcome::Win, won: true);

    $this->get(route('decks.game-stats', ['deck' => $deck->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/GameStats')
            ->has('stats.rows', 12)
            ->has('stats.opponents', 1)
            ->where('stats.opponents.0.name', 'Eldrazi Ramp')
            ->where('currentPage', 'game-stats')
            ->where('timeframe', 'alltime')
            ->where('opponent', null)
        );
});

it('respects the timeframe query parameter', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    $match = createCompletedMatch($deckVersion, $archetype, MatchOutcome::Win, won: true);
    $match->update(['started_at' => now()->subMonths(6)]);

    $this->get(route('decks.game-stats', ['deck' => $deck->id]).'?timeframe=week')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('timeframe', 'week')
            ->where('stats.rows.0.wins', 0)
            ->where('stats.rows.0.losses', 0)
        );
});

it('respects the opponent query parameter', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archA = Archetype::factory()->create();
    $archB = Archetype::factory()->create();

    createCompletedMatch($deckVersion, $archA, MatchOutcome::Win, won: true);
    createCompletedMatch($deckVersion, $archB, MatchOutcome::Loss, won: false);

    $this->get(route('decks.game-stats', ['deck' => $deck->id]).'?opponent='.$archA->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('opponent', $archA->uuid)
            ->where('stats.rows.0.wins', 1)
            ->where('stats.rows.0.losses', 0)
        );
});
