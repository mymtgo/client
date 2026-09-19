<?php

use App\Facades\AppSettings;
use App\Jobs\LinkSyncDeviceJob;
use App\Jobs\RunSyncJob;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\SyncRejection;
use App\Models\SyncState;
use App\Services\Sync\DeckClientId;
use App\Services\Sync\SyncActivity;
use App\Services\Sync\SyncTokens;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('shows an unlinked, empty status', function () {
    SyncActivity::reset();

    $response = $this->getJson(route('settings.device-sync.show'))
        ->assertOk()
        ->json();

    expect($response)->toBe([
        'linked' => false,
        'lastSyncedAt' => null,
        'pending' => 0,
        'pendingByType' => ['match' => 0, 'deck' => 0, 'league' => 0],
        'activity' => [],
        'syncing' => false,
        'notSynced' => 0,
        'slots' => null,
        'rejections' => 0,
        'lastError' => null,
    ]);
});

it('reports syncing while the activity feed is mid-run and not after its terminal line', function () {
    SyncActivity::markQueued();

    expect($this->getJson(route('settings.device-sync.show'))->json('syncing'))->toBeTrue();

    SyncActivity::log('match: downloaded 20 of 441.');

    expect($this->getJson(route('settings.device-sync.show'))->json('syncing'))->toBeTrue();

    SyncActivity::log('Sync complete.');

    expect($this->getJson(route('settings.device-sync.show'))->json('syncing'))->toBeFalse();
});

it('reports null cloud counts when the device is not linked', function () {
    app(SyncTokens::class)->clear();

    $this->getJson(route('settings.device-sync.cloud'))
        ->assertOk()
        ->assertExactJson(['download' => null, 'slots' => null]);
});

it('reports what is waiting in the cloud as server counts minus local rows', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    $version = syncEnabledDeckVersion();
    MtgoMatch::factory()->count(2)->create(['deck_version_id' => $version->id]);
    Deck::factory()->count(8)->create(); // nine local decks with the version's own: more than the server holds, clamps to 0

    // The ledger has to name the seeded deck: the endpoint applies it before
    // counting, and matches are only known ids while their deck is enabled.
    fakeSyncCloudCalls(
        counts: ['match' => 441, 'deck' => 8, 'league' => 3],
        slots: [
            'limit' => 1,
            'used' => 1,
            'decks' => [[
                'client_id' => DeckClientId::for((string) $version->deck->mtgo_id),
                'enabled_at' => '2026-09-01 09:30:00',
                'disabled_at' => null,
                'frees_at' => null,
            ]],
        ],
    );

    $this->getJson(route('settings.device-sync.cloud'))
        ->assertOk()
        ->assertJsonPath('download', ['match' => 439, 'deck' => 0, 'league' => 3]);
});

it('returns the server slot ledger and writes it down', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    $deck = Deck::factory()->create();

    fakeSyncCloudCalls(slots: [
        'limit' => 1,
        'used' => 1,
        'decks' => [[
            'client_id' => DeckClientId::for((string) $deck->mtgo_id),
            'enabled_at' => '2026-09-01 09:30:00',
            'disabled_at' => null,
            'frees_at' => null,
        ]],
    ]);

    $this->getJson(route('settings.device-sync.cloud'))
        ->assertOk()
        ->assertJsonPath('slots', ['limit' => 1, 'used' => 1]);

    // Rule 5: the ledger is the authority, so the read also reconciles the
    // stored summary and the per-deck flags the rest of the app queries.
    expect(AppSettings::syncSlots())->toMatchArray(['limit' => 1, 'used' => 1])
        ->and((bool) $deck->fresh()->cloud_sync_enabled)->toBeTrue();
});

it('reports null counts and null slots when the slot ledger call fails', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    fakeSyncCloudCalls(slotsResponse: Http::response([], 500));

    $this->getJson(route('settings.device-sync.cloud'))
        ->assertOk()
        ->assertExactJson(['download' => null, 'slots' => null]);
});

it('shows linked status with pending, notSynced and rejections computed from seeded rows', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    // Dirty by default: the migration leaves synced_hash null on a freshly
    // created row, which is exactly what DirtyRows::query() looks for.
    // Matches and leagues only count as pending when their deck syncs, so
    // the seeded deck is the enabled one they both hang off.
    $version = syncEnabledDeckVersion();
    MtgoMatch::factory()->count(2)->create(['deck_version_id' => $version->id]);
    League::factory()->create(['deck_version_id' => $version->id]);

    $deletedDeck = Deck::factory()->create();
    $deletedDeck->delete();
    $deletedLeague = League::factory()->create();
    $deletedLeague->delete();

    SyncState::updateOrCreate(
        ['type' => 'match'],
        [
            'canonical_version' => (int) config('sync_client.canonical_version'),
            'last_synced_at' => '2026-08-01 12:00:00',
        ],
    );
    SyncState::updateOrCreate(
        ['type' => 'deck'],
        [
            'canonical_version' => (int) config('sync_client.canonical_version'),
            'last_synced_at' => '2026-09-01 09:30:00',
        ],
    );

    SyncRejection::create([
        'type' => 'match',
        'client_id' => 'token-2',
        'reason' => 'hash_mismatch',
        'hash' => str_repeat('b', 64),
    ]);

    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => []]);

    $response = $this->getJson(route('settings.device-sync.show'))
        ->assertOk()
        ->json();

    expect($response['linked'])->toBeTrue()
        ->and(Carbon::parse($response['lastSyncedAt']))->toEqual(Carbon::parse('2026-09-01 09:30:00'))
        ->and($response['pending'])->toBe(4)
        ->and($response['pendingByType'])->toBe(['match' => 2, 'deck' => 1, 'league' => 1])
        ->and($response['notSynced'])->toBe(2)
        ->and($response['slots'])->toBe(['limit' => 1, 'used' => 1])
        ->and($response['rejections'])->toBe(1);
});

it('has a cloud_sync_enabled flag on decks defaulting to false', function () {
    $deck = Deck::factory()->create();

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeFalse()
        ->and(Schema::hasColumn('sync_state', 'quota_limit'))->toBeFalse();
});

it('dispatches LinkSyncDeviceJob and returns fresh status', function () {
    Bus::fake();

    $response = $this->postJson(route('settings.device-sync.link'))
        ->assertOk()
        ->json();

    Bus::assertDispatched(LinkSyncDeviceJob::class);

    expect($response['linked'])->toBeFalse();
});

it('dispatches RunSyncJob', function () {
    Bus::fake();

    $this->postJson(route('settings.device-sync.run'))->assertOk();

    Bus::assertDispatched(RunSyncJob::class);
});

it('clears sync tokens on unlink', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    expect(app(SyncTokens::class)->linked())->toBeTrue();

    $response = $this->postJson(route('settings.device-sync.unlink'))
        ->assertOk()
        ->json();

    expect(app(SyncTokens::class)->linked())->toBeFalse()
        ->and($response['linked'])->toBeFalse();
});

it('clears deck flags and stored slots on unlink', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => []]);
    $deck = Deck::factory()->create(['cloud_sync_enabled' => true]);
    $updatedAt = $deck->fresh()->updated_at;

    // Clearing the flags must not mark the shelf dirty: a bumped
    // updated_at would push every deck again on the next run after relink.
    $this->travel(1)->hours();

    $this->postJson(route('settings.device-sync.unlink'))->assertOk();

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeFalse()
        ->and(AppSettings::syncSlots())->toBeNull()
        ->and($deck->fresh()->updated_at->eq($updatedAt))->toBeTrue();
});

/**
 * The cloud endpoint makes two authenticated calls, so both need a stub.
 *
 * Pest's global beforeEach installs a catch-all Http::fake() and the first
 * matching stub wins, so the existing callbacks are cleared before the
 * specific ones are registered.
 */
function fakeSyncCloudCalls(
    array $counts = ['match' => 0, 'deck' => 0, 'league' => 0],
    array $slots = ['limit' => 1, 'used' => 0, 'decks' => []],
    mixed $slotsResponse = null,
): void {
    $stubs = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $stubs->setAccessible(true);
    $stubs->setValue(Http::getFacadeRoot(), collect());

    Http::fake([
        '*/api/sync/status' => Http::response(['counts' => $counts]),
        '*/api/sync/decks/slots' => $slotsResponse ?? Http::response($slots),
    ]);
}
