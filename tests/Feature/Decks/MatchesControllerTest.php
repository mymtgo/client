<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->deck = Deck::factory()->create(['format' => 'CMODERN']);
    $this->deckVersion = DeckVersion::factory()->for($this->deck)->create();
    $opponent = Player::create(['username' => 'Opponent']);
    $this->archetypeIds = [];

    foreach (range(1, 3) as $i) {
        $archetype = Archetype::factory()->create(['format' => 'modern']);
        ArchetypeDeck::factory()->for($archetype)->create();
        $this->archetypeIds[] = $archetype->id;
        $match = MtgoMatch::factory()->create([
            'deck_version_id' => $this->deckVersion->id,
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'started_at' => now()->subHours($i),
            'ended_at' => now()->subHours($i)->addMinutes(20),
        ]);
        $game = Game::factory()->create(['match_id' => $match->id, 'won' => true]);
        $game->players()->attach($opponent->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => false]);
        MatchArchetype::create([
            'mtgo_match_id' => $match->id,
            'player_id' => $opponent->id,
            'archetype_id' => $archetype->id,
            'confidence' => 1.0,
        ]);
    }
});

function countExistenceProbes(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $request();

    return collect(DB::getQueryLog())
        ->filter(fn (array $q) => str_starts_with($q['query'], 'select exists'))
        ->count();
}

it('does not run a decklist-existence query per match row', function () {
    $probes = countExistenceProbes(fn () => $this->get(route('decks.matches', $this->deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('matches.data.0.opponentArchetypes.0.archetype.hasDecklist', true)
            ->where('matches.data.2.opponentArchetypes.0.archetype.hasDecklist', true)
        ));

    expect($probes)->toBe(0);
});

it('does not run a decklist-existence query per archetype filter option', function () {
    $probes = countExistenceProbes(function () {
        $archetypes = inertiaPartial(route('decks.matches', $this->deck), 'decks/Matches', ['archetypes'])
            ->assertOk()
            ->json('props.archetypes');

        $mine = collect($archetypes)->whereIn('id', $this->archetypeIds);

        expect($mine)->toHaveCount(3)
            ->and($mine->pluck('hasDecklist')->all())->toBe([true, true, true]);
    });

    expect($probes)->toBe(0);
});

it('omits per-game payloads from match rows', function () {
    $this->get(route('decks.matches', $this->deck))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Matches')
            ->has('matches.data', 3)
            ->has('matches.data.0.gameResults')
            ->where('matches.data.0.opponentName', 'Opponent')
            ->missing('matches.data.0.games')
        );
});
