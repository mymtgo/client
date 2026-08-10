<?php

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('overlay/GameOverlay')
            ->where('drawOdds.librarySize', 24)
            ->has('drawOdds.cards', 2)
        );
});

it('renders null draw odds and a null opponent when no match is live', function () {
    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('overlay/GameOverlay')
            ->where('drawOdds', null)
            ->where('opponent', null)
            ->where('sideboard', null)
        );
});

it('renders the opponent with a manual archetype and its sideboard guide', function () {
    [$match, $opponent] = liveOverlayMatch();

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern', 'color_identity' => 'WUB']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('opponent.username', 'overlayOpp')
            ->where('opponent.archetypeName', 'Esper Blink')
            ->where('opponent.source', 'manual')
            ->where('opponent.manual', true)
            ->where('sideboard.postboardGames', 0)
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

it('omits disabled sections from the payload', function () {
    liveOverlayMatch();

    Http::fake(['*/api/players' => Http::response([], 404)]);

    AppSettings::setOverlayShowDrawOdds(false);
    AppSettings::setOverlayShowSideboard(false);

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('drawOdds', null)
            ->where('sideboard', null)
            ->where('sections.drawOdds', false)
            ->where('sections.sideboard', false)
            ->where('sections.opponent', true)
        );
});
