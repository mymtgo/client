<?php

use App\Actions\Api\CheckApiStatus;
use App\Facades\AppSettings;
use App\Jobs\SubmitMatch;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const OFFLINE_COPY = 'Offline mode is enabled. Turn it off in Settings to refresh archetypes.';

/**
 * Drop the global Http::fake() stub installed by tests/Pest.php's beforeEach
 * so a test's own Http::fake()/preventStrayRequests() call actually takes
 * effect, per the pattern in ApiKeyFreshnessTest.
 */
function clearGlobalHttpFake(): void
{
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
}

it('reports offline rather than pinging the api', function () {
    clearGlobalHttpFake();
    AppSettings::setOffline(true);
    Http::preventStrayRequests();

    expect(CheckApiStatus::run())->toBe(['state' => 'offline']);
});

it('pings the api normally when online', function () {
    clearGlobalHttpFake();
    AppSettings::setOffline(false);
    Http::fake(['*/api/status' => Http::response(['status' => 'ok'], 200)]);

    expect(CheckApiStatus::run())->toBe(['state' => 'ok']);
});

it('redirects the archetype refresh preview with offline copy', function () {
    AppSettings::setOffline(true);

    $this->get(route('archetypes.refresh'))
        ->assertRedirect(route('archetypes.index'))
        ->assertSessionHas('error', 'Offline mode is enabled. Turn it off in Settings to refresh archetypes.');
});

it('does not tell an offline user to check their internet connection', function () {
    AppSettings::setOffline(true);

    $this->get(route('archetypes.refresh'));

    expect(session('error'))->not->toContain('internet connection');
});

it('renders the refresh preview normally when online', function () {
    AppSettings::setOffline(false);
    clearGlobalHttpFake();
    Http::fake(['*/api/archetypes' => Http::response([], 200)]);

    $this->get(route('archetypes.refresh'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('archetypes/Refresh'));
});

it('redirects back from apply with offline copy', function () {
    AppSettings::setOffline(true);

    $this->from(route('archetypes.refresh'))
        ->post(route('archetypes.refresh.apply'))
        ->assertRedirect(route('archetypes.refresh'))
        ->assertSessionHas('error', OFFLINE_COPY);
});

it('applies the refresh normally when online', function () {
    AppSettings::setOffline(false);
    clearGlobalHttpFake();
    Http::fake(['*/api/archetypes' => Http::response([], 200)]);

    $this->post(route('archetypes.refresh.apply'))
        ->assertRedirect(route('archetypes.index'))
        ->assertSessionHas('success');
});

it('returns offline-specific json from the decklist download route', function () {
    $archetype = Archetype::factory()->create();

    AppSettings::setOffline(true);
    clearGlobalHttpFake();
    Http::preventStrayRequests();

    $this->post(route('archetypes.download', $archetype))
        ->assertStatus(422)
        ->assertJson(['error' => 'Offline mode is enabled. Turn it off in Settings to download decklists.']);
});

it('downloads the decklist normally when online', function () {
    $archetype = Archetype::factory()->create(['uuid' => 'download-arch-uuid']);
    Card::factory()->create(['oracle_id' => 'oracle-1', 'mtgo_id' => '100']);

    AppSettings::setOffline(false);
    clearGlobalHttpFake();
    Http::fake([
        '*/api/archetypes/download-arch-uuid/decklists' => Http::response([
            'uuid' => 'download-arch-uuid',
            'name' => $archetype->name,
            'format' => 'modern',
            'decks' => [],
        ], 200),
    ]);

    $this->post(route('archetypes.download', $archetype))
        ->assertSuccessful()
        ->assertJson(['success' => true]);
});

it('returns offline-specific copy instead of submitting matches', function () {
    Queue::fake();
    AppSettings::setOffline(true);

    $this->post(route('settings.submit-matches'))
        ->assertRedirect()
        ->assertSessionHas('error', 'Offline mode is enabled. Turn it off in Settings to submit matches.');

    Queue::assertNothingPushed();
});

it('dispatches submit jobs normally when online', function () {
    Queue::fake();
    AppSettings::setOffline(false);

    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    $opponent = Player::create(['username' => 'Opponent']);
    $archetype = Archetype::factory()->create();

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'submitted_at' => null,
    ]);

    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
    ]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 0.8,
    ]);

    $this->post(route('settings.submit-matches'))->assertRedirect();

    Queue::assertPushed(SubmitMatch::class, 1);
});
