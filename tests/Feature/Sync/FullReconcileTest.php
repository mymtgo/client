<?php

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\SyncState;
use App\Services\Sync\Bundles\MatchBundleBuilder;
use App\Services\Sync\SyncRunner;
use App\Services\Sync\SyncTokens;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Copied from SyncRunnerTest's beforeEach: the Feature suite's global
// beforeEach registers a blanket Http::fake() that would otherwise win over
// any later Http::fake([...]) pattern (first match wins, registration
// order), so stubCallbacks is reset here the same way SyncApiTest and
// SyncRunnerTest do. fakeSync() and manifestRequestsFor(), defined in
// SyncRunnerTest.php, are reused as-is: Pest loads every test file during
// collection, so both are already available as plain global functions by
// the time these tests run, exactly like syncTestMatch() from
// BundleBuilderTest.php.
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
});

it('bypasses self-heal in full mode: a clean row is still sent in dirty', function () {
    $match = syncTestMatch();
    $token = $match->token;
    $hash = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($match->fresh()));

    // Clean per the incremental rules: synced_hash already equals the
    // rebuilt hash, so an incremental run would self-heal this row and
    // never add it to dirty at all.
    $match->forceFill(['synced_hash' => $hash, 'synced_at' => now()])->saveQuietly();
    $updatedAt = $match->fresh()->updated_at->clone()->utc()->format('Y-m-d\\TH:i:s\\Z');

    fakeSync();

    app(SyncRunner::class)->run(true);

    $requests = manifestRequestsFor('match');

    // The server's ManifestRequest requires each dirty entry as an object
    // (client_id, hash, updated_at), not a bare client_id string.
    expect($requests->first()['dirty'])->toBe([[
        'client_id' => $token,
        'hash' => $hash,
        'updated_at' => $updatedAt,
    ]])
        ->and($requests->first()['since'])->toBeNull();
});

it('excludes a free account\'s limited matches and leagues in full mode too, not just incremental', function () {
    // fullQuery() must apply the same free-tier limited gate as
    // DirtyRows::base(): a lapsed supporter's limited history must not
    // surface through a --full run any more than it does through a normal
    // one. AppSettings::isSupporter() defaults to false with no slots
    // stored, so this is already the free-tier case.
    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited', 'cloud_sync_enabled' => true]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    League::factory()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id]);

    fakeSync();

    app(SyncRunner::class)->run(true);

    // `known` is always built from DirtyRows::base(), in every mode, so it
    // proves nothing about fullQuery() specifically; `dirty` is what the
    // full-mode candidate scan (fullQuery(), via candidateQuery()) actually
    // feeds the manifest.
    expect(manifestRequestsFor('league')->flatMap(fn ($r) => collect($r['dirty'])->pluck('client_id'))->all())->toBe([])
        ->and(manifestRequestsFor('match')->flatMap(fn ($r) => collect($r['dirty'])->pluck('client_id'))->all())->toBe([]);
});

it('leaves an unfinished match out of a full reconcile too', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['deck_version_id' => syncEnabledDeckVersion()->id]);

    fakeSync();

    app(SyncRunner::class)->run(true);

    expect(manifestRequestsFor('match')->flatMap(fn ($r) => collect($r['dirty'])->pluck('client_id'))->all())
        ->not->toContain($match->token);
});

it('persists the cursor past a pushed batch even when the run is interrupted afterwards', function () {
    $matchLo = syncTestMatch();
    $matchHi = syncTestMatch();

    fakeSync(
        manifestByType: ['match' => [[
            'upload' => [$matchLo->token, $matchHi->token],
            'download' => ['ghost-token'],
            'tombstones' => [],
        ]]],
        uploadResponse: Http::response(['stored' => [$matchLo->token, $matchHi->token], 'rejected' => []]),
        // A hard failure on the pull request, which runs after the push
        // batch has already been flushed: this aborts the whole run (via
        // run()'s catch(Throwable)) before runType ever reaches the
        // end-of-run save that clears full_cursor and sets last_synced_at,
        // simulating a run killed partway through.
        fetchResponse: Http::response(['error' => 'boom'], 500),
    );

    app(SyncRunner::class)->run(true);

    $state = SyncState::where('type', 'match')->first();

    expect((int) $state->full_cursor)->toBe($matchHi->id)
        ->and($state->last_synced_at)->toBeNull();
});

it('resumes the full scan past a manually set cursor, skipping the ids before it', function () {
    $matchLo = syncTestMatch();
    $matchHi = syncTestMatch();
    $hash = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($matchHi->fresh()));
    $updatedAt = $matchHi->fresh()->updated_at->clone()->utc()->format('Y-m-d\\TH:i:s\\Z');

    SyncState::updateOrCreate(
        ['type' => 'match'],
        ['canonical_version' => (int) config('sync_client.canonical_version'), 'full_cursor' => $matchLo->id],
    );

    fakeSync();

    app(SyncRunner::class)->run(true);

    $requests = manifestRequestsFor('match');

    expect($requests->first()['dirty'])->toBe([[
        'client_id' => $matchHi->token,
        'hash' => $hash,
        'updated_at' => $updatedAt,
    ]]);
});

it('forces full mode for a type on its own when canonical_version mismatches, with no --full flag', function () {
    $match = syncTestMatch();
    $token = $match->token;
    $hash = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($match->fresh()));

    // Clean per the incremental rules, same as the first test: only full
    // mode (here, forced by the version mismatch below) should still send
    // it.
    $match->forceFill(['synced_hash' => $hash, 'synced_at' => now()])->saveQuietly();
    $updatedAt = $match->fresh()->updated_at->clone()->utc()->format('Y-m-d\\TH:i:s\\Z');

    SyncState::updateOrCreate(
        ['type' => 'match'],
        ['canonical_version' => 0],
    );

    fakeSync();

    app(SyncRunner::class)->run();

    $requests = manifestRequestsFor('match');

    expect($requests->first()['dirty'])->toBe([[
        'client_id' => $token,
        'hash' => $hash,
        'updated_at' => $updatedAt,
    ]])
        ->and($requests->first()['since'])->toBeNull();

    $state = SyncState::where('type', 'match')->first();

    expect((int) $state->canonical_version)->toBe((int) config('sync_client.canonical_version'))
        ->and($state->full_cursor)->toBeNull();
});

it('clears full_cursor and stamps canonical_version once a full run for the type completes', function () {
    syncTestMatch();

    SyncState::updateOrCreate(
        ['type' => 'match'],
        ['canonical_version' => (int) config('sync_client.canonical_version'), 'full_cursor' => 999999],
    );

    fakeSync();

    app(SyncRunner::class)->run(true);

    $state = SyncState::where('type', 'match')->first();

    expect($state->full_cursor)->toBeNull()
        ->and((int) $state->canonical_version)->toBe((int) config('sync_client.canonical_version'))
        ->and($state->last_synced_at)->not->toBeNull();
});
