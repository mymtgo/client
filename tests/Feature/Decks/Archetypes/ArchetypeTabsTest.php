<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

/**
 * Two decks in one archetype, each with one complete won match against the
 * same opponent archetype, plus an unrelated deck.
 */
function seedArchetypeWithDecks(): array
{
    $archetype = Archetype::factory()->create(['format' => 'modern', 'name' => 'Eldrazi Tron']);
    $opponent = Archetype::factory()->create(['format' => 'modern', 'name' => 'Rakdos Midrange']);
    $local = Player::firstOrCreate(['username' => 'me']);
    $opp = Player::firstOrCreate(['username' => 'them']);

    $decks = Deck::factory()->count(2)->create(['archetype_id' => $archetype->id, 'format' => 'CModern']);
    $matches = [];

    foreach ($decks as $deck) {
        $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
        $match = MtgoMatch::factory()->create([
            'deck_version_id' => $version->id,
            'format' => 'CModern',
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'started_at' => now()->subDay(),
        ]);
        MatchArchetype::create(['mtgo_match_id' => $match->id, 'archetype_id' => $opponent->id, 'player_id' => $opp->id]);
        $game = Game::factory()->create(['match_id' => $match->id, 'won' => true]);
        $game->players()->attach($local->id, ['is_local' => true, 'on_play' => true, 'instance_id' => 1]);
        $game->players()->attach($opp->id, ['is_local' => false, 'on_play' => false, 'instance_id' => 2]);
        $matches[] = $match;
    }

    Deck::factory()->create(['format' => 'CModern']);

    return [$archetype, $decks, $matches, $opponent];
}

it('renders the matchups tab with the sidebar props and a deferred spread', function () {
    [$archetype] = seedArchetypeWithDecks();

    $this->get(route('decks.archetypes.matchups', $archetype))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/archetypes/Matchups')
            ->where('archetypeHeader.archetype.id', $archetype->id)
            ->where('archetypeHeader.deckCount', 2)
            ->where('filters.archetype', (string) $archetype->id)
            ->where('timeframe', 'alltime')
            ->has('formatOptions')
            ->has('archetypeOptions')
            ->has('unclassifiedCount')
        );
});

it('resolves the matchup spread across every deck in the archetype', function () {
    [$archetype, , , $opponent] = seedArchetypeWithDecks();

    inertiaPartial(route('decks.archetypes.matchups', $archetype), 'decks/archetypes/Matchups', ['matchupSpread'])
        ->assertOk()
        ->assertJsonCount(1, 'props.matchupSpread')
        ->assertJsonPath('props.matchupSpread.0.archetype_id', $opponent->id)
        ->assertJsonPath('props.matchupSpread.0.matches', 2);
});

it('renders the card stats tab with a deferred payload shaped like the deck one', function () {
    [$archetype] = seedArchetypeWithDecks();

    inertiaPartial(route('decks.archetypes.card-stats', $archetype), 'decks/archetypes/CardStats', ['cardStats'])
        ->assertOk()
        ->assertJsonPath('component', 'decks/archetypes/CardStats')
        ->assertJsonPath('props.cardStats.perspective', 'mine')
        ->assertJsonPath('props.cardStats.deckWinrate.games', 2)
        ->assertJsonStructure(['props' => ['cardStats' => ['stats', 'archetypes', 'trust']]]);
});

it('renders the matches tab listing matches from every deck with the deck attached', function () {
    [$archetype, $decks] = seedArchetypeWithDecks();

    $this->get(route('decks.archetypes.matches', $archetype))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/archetypes/Matches')
            ->has('matches.data', 2)
            ->where('matches.data.0.deck.id', fn ($id) => in_array($id, $decks->pluck('id')->all()))
            ->where('unknownArchetypeCount', 0)
            ->where('pendingArchetypeCount', 0)
        );
});

it('filters the matches tab by result', function () {
    [$archetype, , $matches] = seedArchetypeWithDecks();
    $matches[0]->update(['outcome' => MatchOutcome::Loss]);

    $this->get(route('decks.archetypes.matches', [$archetype, 'filter_result' => 'loss']))
        ->assertInertia(fn ($page) => $page->has('matches.data', 1)->where('matches.data.0.id', $matches[0]->id));
});

it('scopes the matches tab to the timeframe', function () {
    [$archetype, , $matches] = seedArchetypeWithDecks();
    $matches[0]->update(['started_at' => now()->subMonths(3)]);

    $this->get(route('decks.archetypes.matches', [$archetype, 'timeframe' => 'week']))
        ->assertInertia(fn ($page) => $page->has('matches.data', 1)->where('timeframe', 'week'));
});

it('redirects to the listing when the archetype has no decks in scope', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern']);

    $this->get(route('decks.archetypes.matchups', $archetype))
        ->assertRedirect(route('decks.index'));
});

it('redirects to the listing for a format-agnostic fallback archetype', function () {
    $fallback = Archetype::factory()->create(['format' => null, 'is_fallback' => true]);
    $deck = Deck::factory()->create(['archetype_id' => $fallback->id]);
    DeckVersion::factory()->create(['deck_id' => $deck->id]);

    $this->get(route('decks.archetypes.matches', $fallback))
        ->assertRedirect(route('decks.index', ['archetype' => $fallback->id]));
});

it('keeps archived decks out of the tabs when hide-archived is on', function () {
    [$archetype, $decks] = seedArchetypeWithDecks();
    AppSettings::setHideArchivedDecks(true);
    $decks[0]->delete();

    $this->get(route('decks.archetypes.matches', $archetype))
        ->assertInertia(fn ($page) => $page->has('matches.data', 1)->where('archetypeHeader.deckCount', 1));
});
