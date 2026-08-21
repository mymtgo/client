<?php

use App\Actions\Overlay\FetchCommunitySideboardRates;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    // tests/Pest.php installs a blanket Http::fake() that always matches and
    // therefore shadows any URL-specific fake registered inside a test. Reset
    // the stub list so per-test Http::fake([...]) calls actually take effect.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

/**
 * A deck version whose deck is classified as $playerUuid, facing $opponent.
 *
 * @return array{0: DeckVersion, 1: Archetype}
 */
function communityRatesFixture(string $playerUuid = 'player-uuid'): array
{
    $player = Archetype::factory()->create(['uuid' => $playerUuid, 'format' => 'modern']);
    $opponent = Archetype::factory()->create(['uuid' => 'opp-uuid', 'format' => 'modern']);

    $deck = Deck::factory()->create(['format' => 'CMODERN', 'archetype_id' => $player->id]);

    $version = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => base64_encode('201:2:1'),
        'modified_at' => now(),
    ]);

    return [$version, $opponent];
}

function fakeCommunityStatsResponse(): void
{
    Http::fake(['*card-stats*' => Http::response([
        'stats' => [
            [
                'oracle_id' => 'o-rip',
                'games' => 80,
                'kept' => ['samples' => 0, 'wins' => 0],
                'seen' => ['samples' => 0, 'wins' => 0],
                'cast' => ['samples' => 0, 'wins' => 0],
                'pregame' => ['samples' => 0, 'wins' => 0],
                'sided_in' => ['samples' => 60],
                'sided_out' => ['samples' => 0],
            ],
            [
                'oracle_id' => 'o-bolt',
                'games' => 80,
                'kept' => ['samples' => 0, 'wins' => 0],
                'seen' => ['samples' => 0, 'wins' => 0],
                'cast' => ['samples' => 0, 'wins' => 0],
                'pregame' => ['samples' => 0, 'wins' => 0],
                'sided_in' => ['samples' => 0],
                'sided_out' => ['samples' => 20],
            ],
        ],
        'archetype_winrate' => ['games' => 80, 'wins' => 44, 'rate' => 0.55],
        'opponents' => [],
        'refreshed_at' => null,
    ], 200)]);
}

it('keys the api sideboard counters by oracle id', function () {
    [$version, $opponent] = communityRatesFixture();
    fakeCommunityStatsResponse();

    $rates = FetchCommunitySideboardRates::run($version, $opponent);

    expect($rates->get('o-rip'))->toBe(['sidedIn' => 60, 'sidedOut' => 0, 'games' => 80]);
    expect($rates->get('o-bolt'))->toBe(['sidedIn' => 0, 'sidedOut' => 20, 'games' => 80]);
});

it('asks the api for postboard games from the player perspective', function () {
    [$version, $opponent] = communityRatesFixture();
    fakeCommunityStatsResponse();

    FetchCommunitySideboardRates::run($version, $opponent);

    Http::assertSent(function ($request) {
        $url = $request->url();

        return str_contains($url, 'player_archetype_uuid=player-uuid')
            && str_contains($url, 'opponent_archetype_uuid=opp-uuid')
            && str_contains($url, 'is_postboard=1')
            && str_contains($url, 'perspective=mine')
            && ! str_contains($url, 'on_play');
    });
});

it('returns nothing and calls nothing when stats sharing is switched off', function () {
    [$version, $opponent] = communityRatesFixture();
    fakeCommunityStatsResponse();

    AppSettings::setShouldTransmitMatches(false);

    expect(FetchCommunitySideboardRates::run($version, $opponent))->toBeEmpty();

    Http::assertNothingSent();
});

it('returns nothing when the deck has no archetype to look up', function () {
    [$version, $opponent] = communityRatesFixture();
    $version->deck->update(['archetype_id' => null]);
    fakeCommunityStatsResponse();

    expect(FetchCommunitySideboardRates::run($version->fresh(), $opponent))->toBeEmpty();

    Http::assertNothingSent();
});

it('degrades to nothing when the api is unavailable', function () {
    [$version, $opponent] = communityRatesFixture();

    Http::fake(['*card-stats*' => Http::response([], 503)]);

    expect(FetchCommunitySideboardRates::run($version, $opponent))->toBeEmpty();
});

it('calls the api once for repeat lookups of the same matchup', function () {
    [$version, $opponent] = communityRatesFixture();
    fakeCommunityStatsResponse();

    FetchCommunitySideboardRates::run($version, $opponent);
    FetchCommunitySideboardRates::run($version, $opponent);

    Http::assertSentCount(1);
});
