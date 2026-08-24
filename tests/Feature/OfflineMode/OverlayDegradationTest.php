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
    // GameOverlayControllerTest and FetchCommunitySideboardRatesTest for the
    // same workaround). Reset the stub list so per-test Http::fake([...])
    // calls actually take effect.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

function overlayDegradationSignature(array $rows): string
{
    return base64_encode(collect($rows)->map(fn ($r) => "{$r[0]}:{$r[1]}:{$r[2]}")->implode('|'));
}

/**
 * A live match with a classified deck facing a classified opponent
 * archetype — the one shape that reaches FetchCommunitySideboardRates::fetch()
 * and lists a sided-in card on the sideboard guide.
 */
function overlayDegradationMatch(): void
{
    Card::create(['mtgo_id' => '301', 'oracle_id' => 'o-degrade', 'name' => 'Thoughtseize', 'type' => 'Sorcery']);

    $deckArchetype = Archetype::factory()->create(['uuid' => 'degrade-player-uuid', 'format' => 'modern']);
    $deck = Deck::factory()->create(['format' => 'CMODERN', 'archetype_id' => $deckArchetype->id]);

    $version = DeckVersion::create([
        'deck_id' => $deck->id,
        // Sideboard flag (last segment) set, so the card lists as sided-in.
        'signature' => overlayDegradationSignature([['301', '2', '1']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '900002', 'token' => 'mt-offline-degrade', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $version->id,
    ]);

    $opponent = Player::create(['username' => 'offlineDegradeOpp']);
    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-offline-degrade', 'started_at' => now()]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);

    $opponentArchetype = Archetype::factory()->create([
        'uuid' => 'degrade-opp-uuid', 'name' => 'Esper Blink', 'format' => 'modern',
    ]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $opponentArchetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);
}

function fakeOverlayDegradationStatsResponse(): void
{
    Http::fake(['*card-stats*' => Http::response([
        'stats' => [[
            'oracle_id' => 'o-degrade',
            'games' => 40,
            'kept' => ['samples' => 0, 'wins' => 0],
            'seen' => ['samples' => 0, 'wins' => 0],
            'cast' => ['samples' => 0, 'wins' => 0],
            'pregame' => ['samples' => 0, 'wins' => 0],
            'sided_in' => ['samples' => 30],
            'sided_out' => ['samples' => 0],
        ]],
        'archetype_winrate' => ['games' => 40, 'wins' => 20, 'rate' => 0.5],
        'opponents' => [],
        'refreshed_at' => null,
    ], 200)]);
}

it('renders live community rates on the overlay while online', function () {
    overlayDegradationMatch();
    fakeOverlayDegradationStatsResponse();

    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sideboard.sidedIn.0.oracleId', 'o-degrade')
            ->where('sideboard.sidedIn.0.communityRate', 75)
        );
});

it('hides a cache-warmed community rate and contacts no api once offline mode is enabled', function () {
    overlayDegradationMatch();
    fakeOverlayDegradationStatsResponse();

    // Warm the 6-hour community-rate cache the same way the overlay's 5s poll
    // would while the app was still online.
    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sideboard.sidedIn.0.communityRate', 75));

    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    // The cache entry from the request above is still well inside its 6-hour
    // TTL. Offline mode is a deliberate opt-out, not an outage: it must not
    // leak that pre-fetched community data back onto the panel, and the
    // overlay still has to render successfully without touching the network.
    $this->get(route('overlay.game'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sideboard.sidedIn.0.oracleId', 'o-degrade')
            ->where('sideboard.sidedIn.0.communityRate', null)
        );
});

it('renders the overlay without contacting the api when no match is live', function () {
    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    $this->get(route('overlay.game'))->assertSuccessful();
});
