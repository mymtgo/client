<?php

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    // tests/Pest.php installs a blanket Http::fake() that always matches and
    // therefore shadows any URL-specific fake registered inside a test (see
    // FetchOpponentLeagueArchetypeTest and ResolveOverlayOpponentTest for the
    // same workaround). Reset the stub list so per-test Http::fake([...])
    // calls actually take effect.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

function overlaySignature(array $rows): string
{
    return base64_encode(collect($rows)->map(fn ($r) => "{$r[0]}:{$r[1]}:{$r[2]}")->implode('|'));
}

/**
 * Request the overlay the way Inertia resolves a deferred prop: the initial
 * response omits `drawOdds` and `archetypes` entirely, and only a partial
 * reload naming them runs their closures.
 *
 * The response is XHR JSON, so `assertInertia` (which reads the `page` view
 * data of an HTML response) does not apply — assert with `assertJsonPath` on
 * `props.*` instead.
 */
function overlayPartial(array $only): TestResponse
{
    // Taken from the middleware rather than Inertia::getVersion(): the facade
    // only learns the version once a request has passed through the middleware,
    // and a mismatched X-Inertia-Version is answered with a 409.
    $version = app(HandleInertiaRequests::class)->version(request());

    return test()->get(route('overlay.game'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Data' => implode(',', $only),
        'X-Inertia-Partial-Component' => 'overlay/GameOverlay',
    ]);
}

function liveOverlayMatch(): array
{
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();

    $version = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => overlaySignature([['101', '20', '0'], ['102', '4', '0']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '800001', 'token' => 'mt-overlay', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $version->id,
    ]);

    $opponent = Player::create(['username' => 'overlayOpp']);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-overlay', 'started_at' => now()]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);

    return [$match, $opponent, $deck, $version];
}

it('renders draw odds for the active match deck', function () {
    liveOverlayMatch();

    Http::fake(['*/api/players' => Http::response([], 404)]);

    overlayPartial(['drawOdds'])
        ->assertOk()
        ->assertJsonPath('component', 'overlay/GameOverlay')
        ->assertJsonPath('props.drawOdds.librarySize', 24)
        ->assertJsonCount(2, 'props.drawOdds.cards');
});

it('defers draw odds so a poll that excludes it never computes it', function () {
    liveOverlayMatch();

    Http::fake(['*/api/players' => Http::response([], 404)]);

    $response = $this->get(route('overlay.game'))->assertOk();

    // Absent from the initial response, and listed as deferred so Inertia
    // fetches it automatically right after first paint.
    $response->assertInertia(fn ($page) => $page->missing('drawOdds'));

    expect($response->viewData('page')['deferredProps']['default'] ?? [])->toContain('drawOdds');

    // The poll's own `only` list resolves everything it names and nothing else,
    // so the deferred closure never runs on a tick.
    overlayPartial(['opponent', 'sideboard', 'notes', 'isSideboarding', 'sections', 'hasMatch', 'hasDeck', 'hasArchetype', 'format'])
        ->assertOk()
        ->assertJsonMissingPath('props.drawOdds')
        ->assertJsonPath('props.hasMatch', true);
});

it('renders null draw odds and a null opponent when no match is live', function () {
    overlayPartial(['drawOdds', 'opponent', 'sideboard'])
        ->assertOk()
        ->assertJsonPath('component', 'overlay/GameOverlay')
        ->assertJsonPath('props.drawOdds', null)
        ->assertJsonPath('props.opponent', null)
        ->assertJsonPath('props.sideboard', null);
});

it('sends the humanised format so the archetype dropdown can match on it', function () {
    liveOverlayMatch();

    Http::fake(['*/api/players' => Http::response([], 404)]);

    // The match stores MTGO's raw `CModern`. The dropdown filters against
    // `archetypes.format`, which DownloadArchetypes stores lowercased as
    // 'modern', via the same humanised value MatchData already sends.
    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('format', 'Modern'));
});

it('sends a null format when no match is live', function () {
    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('format', null));
});

it('renders the opponent with a manual archetype and its sideboard guide', function () {
    [$match, $opponent, $deck] = liveOverlayMatch();

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern', 'color_identity' => 'WUB']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    DeckArchetypeNote::create([
        'deck_id' => $deck->id,
        'archetype_id' => $archetype->id,
        'body' => 'Bring in the extra removal, they run few threats worth countering.',
    ]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('opponent.username', 'overlayOpp')
            ->where('opponent.archetypeName', 'Esper Blink')
            ->where('opponent.source', 'manual')
            ->where('opponent.manual', true)
            ->where('sideboard.postboardGames', 0)
            ->has('notes.current', 1)
            ->where('notes.current.0.body', 'Bring in the extra removal, they run few threats worth countering.')
        );
});

it('renders the opponent with no archetype when nothing resolves', function () {
    liveOverlayMatch();

    Http::fake(['*/api/players' => Http::response([], 404)]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('opponent.username', 'overlayOpp')
            ->where('opponent.archetypeId', null)
            ->where('opponent.source', 'none')
            ->where('sideboard', null)
            ->has('notes.current', 0)
        );
});

it('makes no HTTP request while building the payload for a manual pick', function () {
    [$match, $opponent] = liveOverlayMatch();

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    Http::preventStrayRequests();

    $this->get(route('overlay.game'))->assertOk();
});

it('reports hasMatch and hasDeck from the match itself, independent of section toggles', function () {
    liveOverlayMatch();

    Http::fake(['*/api/players' => Http::response([], 404)]);

    // Toggle sections independently of each other — opponent and sideboard
    // stay on, draw odds goes off — so a live match with a deck version but
    // no resolved archetype must still report hasMatch/hasDeck true even
    // though `sideboard` itself is null (no archetype, not "no deck").
    AppSettings::setOverlayShowDrawOdds(false);

    overlayPartial(['sections', 'drawOdds', 'sideboard', 'hasMatch', 'hasDeck', 'hasArchetype'])
        ->assertOk()
        ->assertJsonPath('props.sections.opponent', true)
        ->assertJsonPath('props.sections.drawOdds', false)
        ->assertJsonPath('props.sections.sideboard', true)
        ->assertJsonPath('props.drawOdds', null)
        ->assertJsonPath('props.sideboard', null)
        ->assertJsonPath('props.hasMatch', true)
        ->assertJsonPath('props.hasDeck', true)
        ->assertJsonPath('props.hasArchetype', false);
});

it('omits disabled sections from the payload', function () {
    liveOverlayMatch();

    Http::fake(['*/api/players' => Http::response([], 404)]);

    AppSettings::setOverlayShowDrawOdds(false);
    AppSettings::setOverlayShowSideboard(false);

    overlayPartial(['drawOdds', 'sideboard', 'sections'])
        ->assertOk()
        ->assertJsonPath('props.drawOdds', null)
        ->assertJsonPath('props.sideboard', null)
        ->assertJsonPath('props.sections.drawOdds', false)
        ->assertJsonPath('props.sections.sideboard', false)
        ->assertJsonPath('props.sections.opponent', true);
});

it('keeps the sideboard guide and notes when the opponent header is switched off', function () {
    [$match, $opponent, $deck] = liveOverlayMatch();

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    DeckArchetypeNote::create([
        'deck_id' => $deck->id,
        'archetype_id' => $archetype->id,
        'body' => 'Keep the graveyard hate, cut a land.',
    ]);

    // The header is off but the sideboard section is on. Both the guide and its
    // notes derive from the opponent's archetype, so the opponent still has to
    // be resolved — only the `opponent` prop itself is suppressed.
    AppSettings::setOverlayShowOpponent(false);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sections.opponent', false)
            ->where('opponent', null)
            ->where('hasArchetype', true)
            ->where('sideboard.postboardGames', 0)
            ->has('sideboard.sidedIn')
            ->has('notes.current', 1)
            ->where('notes.current.0.body', 'Keep the graveyard hate, cut a land.')
        );
});

it('still responds when a pre-upgrade league cache entry is missing its uuid', function () {
    liveOverlayMatch();

    // A cache entry written before the overlay shipped held only name + colors.
    // The file cache survives the restart that installs the upgrade, so the
    // versioned key must not read it — and the shape is re-checked regardless.
    Cache::put('overlayOpp_archetype', ['name' => 'Esper Blink', 'colors' => 'WUB'], now()->addHour());
    Cache::put('overlayOpp_archetype_v2', ['name' => 'Esper Blink', 'colors' => 'WUB'], now()->addHour());

    Http::fake(['*/api/players' => Http::response([], 404)]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('opponent.username', 'overlayOpp')
            ->where('opponent.archetypeId', null)
            ->where('hasArchetype', false)
        );
});

it('annotates the sideboard guide with community rates for a classified deck', function () {
    [$match, $opponent, $deck, $version] = liveOverlayMatch();

    $deckArchetype = Archetype::factory()->create(['uuid' => 'my-deck-uuid', 'format' => 'modern']);
    $deck->update(['archetype_id' => $deckArchetype->id, 'format' => 'CMODERN']);

    // 102 moved to the sideboard so Lightning Bolt is listed as a sided-in card.
    $version->update(['signature' => overlaySignature([['101', '20', '0'], ['102', '4', '1']])]);

    $opponentArchetype = Archetype::factory()->create([
        'uuid' => 'opp-uuid', 'name' => 'Esper Blink', 'format' => 'modern',
    ]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $opponentArchetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    Http::fake(['*card-stats*' => Http::response([
        'stats' => [[
            'oracle_id' => 'o-bolt',
            'games' => 50,
            'kept' => ['samples' => 0, 'wins' => 0],
            'seen' => ['samples' => 0, 'wins' => 0],
            'cast' => ['samples' => 0, 'wins' => 0],
            'pregame' => ['samples' => 0, 'wins' => 0],
            'sided_in' => ['samples' => 45],
            'sided_out' => ['samples' => 0],
        ]],
        'archetype_winrate' => ['games' => 50, 'wins' => 30, 'rate' => 0.6],
        'opponents' => [],
        'refreshed_at' => null,
    ], 200)]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sideboard.sidedIn.0.oracleId', 'o-bolt')
            ->where('sideboard.sidedIn.0.communitySidedIn', 45)
            ->where('sideboard.sidedIn.0.communityRate', 90)
        );
});

it('renders the sideboard guide without community rates when stats sharing is off', function () {
    [$match, $opponent, $deck, $version] = liveOverlayMatch();

    $deckArchetype = Archetype::factory()->create(['uuid' => 'my-deck-uuid', 'format' => 'modern']);
    $deck->update(['archetype_id' => $deckArchetype->id, 'format' => 'CMODERN']);
    $version->update(['signature' => overlaySignature([['101', '20', '0'], ['102', '4', '1']])]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => Archetype::factory()->create(['uuid' => 'opp-uuid', 'format' => 'modern'])->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    AppSettings::setOffline(true);

    Http::preventStrayRequests();

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sideboard.sidedIn.0.oracleId', 'o-bolt')
            ->where('sideboard.sidedIn.0.communityRate', null)
        );
});

it('swaps the sideboard guide when a new archetype is pinned', function () {
    [$match, $opponent, $deck, $version] = liveOverlayMatch();

    $deckArchetype = Archetype::factory()->create(['uuid' => 'my-deck-uuid', 'format' => 'modern']);
    $deck->update(['archetype_id' => $deckArchetype->id, 'format' => 'CMODERN']);
    $version->update(['signature' => overlaySignature([['101', '20', '0'], ['102', '4', '1']])]);

    $first = Archetype::factory()->create(['uuid' => 'first-uuid', 'name' => 'Esper Blink', 'format' => 'modern']);
    $second = Archetype::factory()->create(['uuid' => 'second-uuid', 'name' => 'Ruby Storm', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $first->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    // Each opponent archetype gets its own community payload, so a stale
    // response would be visible rather than merely unchanged.
    $rates = fn (int $sidedIn, int $games) => Http::response([
        'stats' => [[
            'oracle_id' => 'o-bolt',
            'games' => $games,
            'kept' => ['samples' => 0, 'wins' => 0],
            'seen' => ['samples' => 0, 'wins' => 0],
            'cast' => ['samples' => 0, 'wins' => 0],
            'pregame' => ['samples' => 0, 'wins' => 0],
            'sided_in' => ['samples' => $sidedIn],
            'sided_out' => ['samples' => 0],
        ]],
        'archetype_winrate' => ['games' => $games, 'wins' => 0, 'rate' => 0.0],
        'opponents' => [],
        'refreshed_at' => null,
    ], 200);

    Http::fake([
        '*opponent_archetype_uuid=first-uuid*' => $rates(20, 100),
        '*opponent_archetype_uuid=second-uuid*' => $rates(90, 100),
    ]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sideboard.sidedIn.0.communityRate', 20));

    $this->post(route('overlay.archetype'), ['archetype_id' => $second->id])->assertRedirect();

    // The same partial reload GameOverlay.vue asks for after a pick.
    overlayPartial(['opponent', 'sideboard', 'notes', 'hasArchetype'])
        ->assertOk()
        ->assertJsonPath('props.opponent.archetypeName', 'Ruby Storm')
        ->assertJsonPath('props.sideboard.sidedIn.0.communityRate', 90);
});

it('renders revealed opponent cards for the active match', function () {
    [$match, $opponent] = liveOverlayMatch();

    $game = $match->games()->first();
    $game->players()->updateExistingPivot($opponent->id, [
        'deck_json' => [['mtgo_id' => 102, 'quantity' => 2]],
    ]);

    overlayPartial(['reveals', 'sections'])
        ->assertSuccessful()
        ->assertJsonPath('props.sections.reveals', true)
        ->assertJsonPath('props.reveals.0.name', 'Lightning Bolt')
        ->assertJsonPath('props.reveals.0.quantity', 2);
});

it('omits reveals when the section is disabled', function () {
    AppSettings::setOverlayShowReveals(false);
    liveOverlayMatch();

    overlayPartial(['reveals', 'sections'])
        ->assertSuccessful()
        ->assertJsonPath('props.sections.reveals', false)
        ->assertJsonPath('props.reveals', null);
});

it('shows the authored plan instead of history when a guide with cards exists for the matchup', function () {
    [$match, $opponent, $deck, $version] = liveOverlayMatch();

    // Add a sideboard card to the live deck.
    Card::create(['mtgo_id' => '103', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment']);
    $version->update(['signature' => overlaySignature([['101', '20', '0'], ['102', '4', '0'], ['103', '2', '1']])]);

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-rip', 'quantity' => 1]);
    SideboardGuideCard::factory()->out()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-bolt', 'quantity' => 1]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sideboard.hasPlan', true)
            ->has('sideboard.sidedIn', 1)
            ->where('sideboard.sidedIn.0.oracleId', 'o-rip')
            ->where('sideboard.sidedIn.0.quantity', 1)
            ->has('sideboard.sidedOut', 1)
            ->where('sideboard.sidedOut.0.oracleId', 'o-bolt')
        );
});

it('falls back to history when the guide for the matchup has no cards', function () {
    [$match, $opponent, $deck, $version] = liveOverlayMatch();

    Card::create(['mtgo_id' => '103', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment']);
    $version->update(['signature' => overlaySignature([['101', '20', '0'], ['102', '4', '0'], ['103', '2', '1']])]);

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sideboard.hasPlan', false)
            // History scope lists the whole sideboard.
            ->has('sideboard.sidedIn', 1)
            ->where('sideboard.sidedIn.0.oracleId', 'o-rip')
            ->where('sideboard.sidedIn.0.quantity', 2)
        );
});
