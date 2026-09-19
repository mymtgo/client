<?php

use App\Events\AppNotification;
use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\MtgoMatch;
use App\Models\SyncRejection;
use App\Services\Sync\Bundles\MatchBundleBuilder;
use App\Services\Sync\Bundles\MatchBundleImporter;
use App\Services\Sync\DeckClientId;
use App\Services\Sync\SyncActivity;
use App\Services\Sync\SyncRunner;
use App\Services\Sync\SyncTokens;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

// The Feature test suite's global beforeEach registers a blanket Http::fake()
// that matches every URL and wins over any later Http::fake([...]) pattern
// (Laravel evaluates fakes in registration order, first match wins). Reset
// stubCallbacks here so each fakeSync() call below is the only fake in
// play, the same reset SyncApiTest uses.
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
});

/**
 * Fakes the three sync endpoints. $manifestByType maps a type to a list of
 * response bodies, consumed in call order and holding on the last one once
 * exhausted (so a single-entry list answers every manifest call for that
 * type, partial chunks included); a type absent from the map gets the
 * empty default on every call. $uploadResponse/$fetchResponse answer every
 * call to their endpoint.
 *
 * @param  array<string, list<array<string, mixed>>>  $manifestByType
 */
function fakeSync(array $manifestByType = [], mixed $uploadResponse = null, mixed $fetchResponse = null): void
{
    // Http::fake() merges new stubs onto the existing stub list rather than
    // replacing it (first-registered match wins), so a second call within
    // the same test would otherwise leave the first call's responses (and
    // its now-stale request-count expectations) shadowing this one. Reset
    // the same way the suite-global beforeEach does.
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    // slots is null rather than an empty ledger on purpose: an empty ledger
    // is a real instruction to turn every deck off, which would gate every
    // match and league out of the tests that do not care about slots. A test
    // that does care passes its own ledger through $manifestByType.
    $default = ['upload' => [], 'download' => [], 'tombstones' => [], 'slots' => null];
    $cursors = [];

    Http::fake([
        '*/api/sync/manifest' => function ($request) use ($manifestByType, $default, &$cursors) {
            $type = $request['type'];
            $responses = $manifestByType[$type] ?? [$default];
            $index = $cursors[$type] ?? 0;
            $cursors[$type] = $index + 1;

            return Http::response($responses[$index] ?? $responses[array_key_last($responses)]);
        },
        '*/api/sync/blobs/fetch' => $fetchResponse ?? Http::response(['blobs' => []]),
        '*/api/sync/blobs' => $uploadResponse ?? Http::response(['stored' => [], 'rejected' => []]),
    ]);
}

/**
 * Every manifest request sent, in send order, for one type.
 *
 * @return Collection<int, Request>
 */
function manifestRequestsFor(string $type)
{
    return collect(Http::recorded(fn ($request) => $request->url() === 'https://mymtgo.com/api/sync/manifest' && $request['type'] === $type))
        ->map(fn (array $pair) => $pair[0])
        ->values();
}

/**
 * Deletes a match's whole local graph so a later pull genuinely recreates
 * it rather than refreshing an already-present row. Mirrors
 * BundleRoundTripTest's syncRoundTrip cleanup.
 */
function wipeMatchLocally(MtgoMatch $match): void
{
    $match->games()->each(function ($game) {
        DB::table('game_player')->where('game_id', $game->id)->delete();
        $game->timeline()->delete();
        CardGameStat::where('game_id', $game->id)->delete();
    });
    $match->archetypes()->delete();
    $match->games()->delete();
    $match->delete();
}

it('pushes a dirty match, carrying the right hash and sidecar, and sets synced_hash once stored', function () {
    $match = syncTestMatch();
    $token = $match->token;
    $bundle = app(MatchBundleBuilder::class)->build($match->fresh());
    $hash = CanonicalJson::hash($bundle);
    $sidecar = app(MatchBundleBuilder::class)->sidecar($match->fresh());

    fakeSync(
        manifestByType: ['match' => [['upload' => [$token], 'download' => [], 'tombstones' => []]]],
        uploadResponse: Http::response(['stored' => [$token], 'rejected' => []]),
    );

    app(SyncRunner::class)->run();

    Http::assertSent(function ($request) use ($token, $hash, $sidecar) {
        if ($request->url() !== 'https://mymtgo.com/api/sync/blobs') {
            return false;
        }

        $body = $request->body();

        return str_contains($body, 'name="meta[0][client_id]"')
            && str_contains($body, $token)
            && str_contains($body, 'name="meta[0][hash]"')
            && str_contains($body, $hash)
            // A real value, not just the field name: proves the sidecar
            // this row actually built (not an empty array or a stub) made
            // it into the request.
            && str_contains($body, 'name="meta[0][sidecar][format]"')
            && str_contains($body, (string) $sidecar['format'])
            && $request->hasFile('blobs[0]', filename: "{$token}.json.gz");
    });

    $reloaded = $match->fresh();

    expect($reloaded->synced_hash)->toBe($hash)
        ->and($reloaded->synced_at)->not->toBeNull();
});

it('self-heals a touched-but-unchanged match: no upload, but a fresh synced_at', function () {
    $match = syncTestMatch();
    $hash = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($match->fresh()));

    $match->forceFill(['synced_hash' => $hash, 'synced_at' => now()])->saveQuietly();
    $beforeSyncedAt = $match->fresh()->synced_at;

    // Second-granularity timestamp columns: without the tick forward, the
    // touch below and synced_at above can land in the same second, making
    // the "fresh synced_at" assertion flaky (same root cause noted in
    // Task 2's report).
    $this->travelTo(now()->addSecond());
    $match->touch(); // bumps updated_at past synced_at with no real content change

    fakeSync();

    app(SyncRunner::class)->run();

    $reloaded = $match->fresh();

    expect($reloaded->synced_hash)->toBe($hash)
        ->and(Carbon::parse($reloaded->synced_at)->isAfter($beforeSyncedAt))->toBeTrue();

    Http::assertNotSent(fn ($request) => $request->url() === 'https://mymtgo.com/api/sync/blobs');
});

it('chunks the manifest at manifest_chunk with a separate, always-final known+last call', function () {
    config(['sync_client.limits.manifest_chunk' => 1]);

    syncTestMatch();
    syncTestMatch();

    fakeSync();

    app(SyncRunner::class)->run();

    $requests = manifestRequestsFor('match');

    expect($requests)->toHaveCount(3);

    [$first, $second, $third] = $requests->all();

    expect($first['known'])->toBe([])
        ->and($first['last'])->toBeFalse()
        ->and($first['dirty'])->toHaveCount(1)
        // Every dirty entry is an object, not a bare client_id string: the
        // server's ManifestRequest requires client_id/hash/updated_at.
        ->and(array_keys($first['dirty'][0]))->toBe(['client_id', 'hash', 'updated_at'])
        ->and($second['known'])->toBe([])
        ->and($second['last'])->toBeFalse()
        ->and($second['dirty'])->toHaveCount(1)
        ->and($third['dirty'])->toBe([])
        ->and($third['last'])->toBeTrue()
        ->and($third['known'])->toHaveCount(2);
});

it('sends each dirty entry as an object matching the server contract exactly', function () {
    $match = syncTestMatch();

    fakeSync();

    app(SyncRunner::class)->run();

    $entry = manifestRequestsFor('match')->first()['dirty'][0];

    expect(array_keys($entry))->toBe(['client_id', 'hash', 'updated_at'])
        ->and($entry['hash'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($entry['updated_at'])->toMatch('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/');
});

it('fetches a download id, hash-verifies it, and imports it', function () {
    $original = syncTestMatch();
    $token = $original->token;
    $bundle = app(MatchBundleBuilder::class)->build($original->fresh());
    $hash = CanonicalJson::hash($bundle);
    $gzip = gzencode(CanonicalJson::encode($bundle), 6);

    wipeMatchLocally($original);

    expect(MtgoMatch::where('token', $token)->exists())->toBeFalse();

    fakeSync(
        manifestByType: ['match' => [['upload' => [], 'download' => [$token], 'tombstones' => []]]],
        fetchResponse: Http::response(['blobs' => [
            ['client_id' => $token, 'hash' => $hash, 'data' => base64_encode($gzip)],
        ]]),
    );

    app(SyncRunner::class)->run();

    $reborn = MtgoMatch::where('token', $token)->first();

    expect($reborn)->not->toBeNull()
        ->and($reborn->synced_hash)->toBe($hash);
});

it('parks a rejected blob, skips it unchanged, and retries it once its hash changes', function () {
    $match = syncTestMatch();
    $token = $match->token;
    $hash1 = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($match->fresh()));

    fakeSync(
        manifestByType: ['match' => [['upload' => [$token], 'download' => [], 'tombstones' => []]]],
        uploadResponse: Http::response(['stored' => [], 'rejected' => [['client_id' => $token, 'reason' => 'hash_mismatch']]]),
    );

    app(SyncRunner::class)->run();

    $rejection = SyncRejection::where('type', 'match')->where('client_id', $token)->first();

    expect($rejection)->not->toBeNull()
        ->and($rejection->reason)->toBe('hash_mismatch')
        ->and($rejection->hash)->toBe($hash1)
        ->and($match->fresh()->synced_hash)->toBeNull();

    // Unchanged content, run again: the rejection's hash still matches, so
    // it is not resent.
    fakeSync(
        manifestByType: ['match' => [['upload' => [$token], 'download' => [], 'tombstones' => []]]],
    );

    app(SyncRunner::class)->run();

    Http::assertNotSent(fn ($request) => $request->url() === 'https://mymtgo.com/api/sync/blobs');
    expect(SyncRejection::where('type', 'match')->where('client_id', $token)->exists())->toBeTrue();

    // The content actually changes now, giving it a new hash: it is retried.
    $this->travelTo(now()->addSecond());
    $match->forceFill(['notes' => 'updated after rejection'])->save();
    $hash2 = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($match->fresh()));

    fakeSync(
        manifestByType: ['match' => [['upload' => [$token], 'download' => [], 'tombstones' => []]]],
        uploadResponse: Http::response(['stored' => [$token], 'rejected' => []]),
    );

    app(SyncRunner::class)->run();

    expect($match->fresh()->synced_hash)->toBe($hash2)
        ->and(SyncRejection::where('type', 'match')->where('client_id', $token)->exists())->toBeFalse();
});

it('runs the deck, league then match manifest calls in that fixed order', function () {
    fakeSync();

    app(SyncRunner::class)->run();

    $types = collect(Http::recorded(fn ($request) => $request->url() === 'https://mymtgo.com/api/sync/manifest'))
        ->map(fn (array $pair) => $pair[0]['type'])
        ->values();

    expect($types->toArray())->toBe(['deck', 'league', 'match']);
});

it('gives every type a manifest call even when only one type has work', function () {
    syncTestMatch();

    fakeSync();

    app(SyncRunner::class)->run();

    $requests = Http::recorded(fn ($request) => $request->url() === 'https://mymtgo.com/api/sync/manifest');

    expect(count($requests))->toBeGreaterThanOrEqual(3);

    $types = collect($requests)->map(fn (array $pair) => $pair[0]['type'])->unique()->sort()->values();

    expect($types->toArray())->toBe(['deck', 'league', 'match']);
});

it('eager loads match relations once per chunk instead of once per row', function () {
    // MatchBundleBuilder::RELATIONS lists 7 relation paths (games.timeline,
    // games.players, games.cardGameStats, archetypes.archetype,
    // archetypes.player, deckVersion.deck, league). If SyncRunner fell back
    // to the builder's own per-row loadMissing() instead of eager-loading
    // the candidate query, 3 dirty matches sharing one lazyById(25) chunk
    // would cost at least 3 x 7 = 21 relation queries during the manifest
    // scan alone, on top of every other query the run makes (sync_state
    // bookkeeping, DirtyRows::knownIds, and so on). With the relations
    // eager-loaded once for the whole chunk, that cost collapses to
    // roughly one query per relation path regardless of how many matches
    // are in the chunk. Measured directly: with eager loading wired in
    // this run costs 26 queries; with it stripped out (the builder's
    // per-row loadMissing() fallback left to do all the work) the same
    // run costs 48. 35 sits squarely between those two, so it is a robust,
    // exact-count-independent signal that eager loading is actually wired
    // in rather than merely available as a fallback.
    syncTestMatch();
    syncTestMatch();
    syncTestMatch();

    // upload/download empty: this isolates the manifest scan's own query
    // cost rather than also folding in the push phase's rebuild.
    fakeSync();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    app(SyncRunner::class)->run();

    expect($queryCount)->toBeLessThan(35);
});

it('pushes 30+ dirty matches oldest-first by started_at, each exactly once, across more than one candidate-fetch chunk', function () {
    // started_at is assigned in the exact opposite order to id (id
    // ascending as created, started_at descending), and pushCandidateRows
    // fetches candidates 25 at a time: the bug this guards against
    // (orderBy('started_at') applied straight to the lazyById-paginated
    // query) only shows up once the started_at order actually disagrees
    // with id order and there is more than one page, since Laravel's
    // forPageAfterId only strips id-column orders and silently keeps
    // paging by id underneath.
    $matches = collect(range(0, 31))->map(fn (int $i) => syncTestMatch(['started_at' => now()->subDays($i)]));
    $tokens = $matches->pluck('token')->all();
    $expectedOrder = $matches->reverse()->pluck('token')->values()->all();

    $uploadedOrder = [];

    fakeSync(
        manifestByType: ['match' => [['upload' => $tokens, 'download' => [], 'tombstones' => []]]],
        uploadResponse: function ($request) use (&$uploadedOrder) {
            preg_match_all('/name="blobs\[\d+\]"; filename="([^"]+)\.json\.gz"/', (string) $request->body(), $matches);
            array_push($uploadedOrder, ...$matches[1]);

            return Http::response(['stored' => $matches[1], 'rejected' => []]);
        },
    );

    app(SyncRunner::class)->run();

    expect($uploadedOrder)->toBe($expectedOrder);
});

it('marks a pushed match clean with synced_at equal to the row\'s own updated_at, not the wall-clock time the confirmation arrived', function () {
    $match = syncTestMatch();
    $preUpdatedAt = $match->fresh()->updated_at;
    $token = $match->token;

    fakeSync(
        manifestByType: ['match' => [['upload' => [$token], 'download' => [], 'tombstones' => []]]],
        uploadResponse: Http::response(['stored' => [$token], 'rejected' => []]),
    );

    // Time moves forward between the row's content being captured (the
    // manifest scan / push build, both inside run()) and the mark-clean
    // write that follows the server's stored confirmation. The old
    // forceFill(...)->saveQuietly() stamped synced_at with now() at that
    // point, which would land on the travelled time below, not
    // $preUpdatedAt.
    $this->travelTo(now()->addMinutes(5));

    app(SyncRunner::class)->run();

    $reloaded = $match->fresh();

    expect(Carbon::parse($reloaded->synced_at)->equalTo($preUpdatedAt))->toBeTrue()
        ->and(Carbon::parse($reloaded->synced_at)->equalTo(Carbon::now()))->toBeFalse()
        ->and(Carbon::parse($reloaded->updated_at)->equalTo($preUpdatedAt))->toBeTrue();
});

it('continues to the next download slice after one slice makes no progress, instead of abandoning the whole pull', function () {
    config(['sync_client.limits.blobs_per_request' => 1]);

    $original = syncTestMatch();
    $token = $original->token;
    $bundle = app(MatchBundleBuilder::class)->build($original->fresh());
    $hash = CanonicalJson::hash($bundle);
    $gzip = gzencode(CanonicalJson::encode($bundle), 6);

    wipeMatchLocally($original);

    // blobs_per_request: 1 forces two single-id slices out of
    // ['ghost-token', $token]. The first slice never resolves (the server
    // has nothing for 'ghost-token'), which used to abort the whole pull;
    // the second slice, for $token, must still be tried.
    fakeSync(
        manifestByType: ['match' => [['upload' => [], 'download' => ['ghost-token', $token], 'tombstones' => []]]],
        fetchResponse: function ($request) use ($token, $hash, $gzip) {
            if (in_array($token, $request['client_ids'], true)) {
                return Http::response(['blobs' => [
                    ['client_id' => $token, 'hash' => $hash, 'data' => base64_encode($gzip)],
                ]]);
            }

            return Http::response(['blobs' => []]);
        },
    );

    app(SyncRunner::class)->run();

    expect(MtgoMatch::where('token', $token)->exists())->toBeTrue();
});

it('records the aborting error for the settings card and clears it on the next clean run', function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
    Http::fake(['*/api/sync/*' => Http::response(['message' => 'The dirty.7.client_id field format is invalid.'], 422)]);

    app(SyncRunner::class)->run();

    expect(AppSettings::syncLastError())
        ->toContain('422')
        ->toContain('dirty.7.client_id');

    fakeSync();

    app(SyncRunner::class)->run();

    expect(AppSettings::syncLastError())->toBeNull();
});

it('sends fetch client_ids as strings even when the manifest returned a numeric id as an int', function () {
    // A server that passed "108886838" through a PHP array key serializes
    // it as a JSON int; the fetch endpoint rejects non-string client_ids.
    fakeSync(manifestByType: [
        'deck' => [['upload' => [], 'download' => [108886838], 'tombstones' => []]],
    ]);

    app(SyncRunner::class)->run();

    $fetches = collect(Http::recorded(fn ($request) => str_contains($request->url(), '/api/sync/blobs/fetch')));

    expect($fetches)->not->toBeEmpty()
        ->and($fetches->first()[0]['client_ids'])->toBe(['108886838']);
});

it('writes a fresh console feed for each run, ending in Sync complete', function () {
    fakeSync();

    app(SyncRunner::class)->run();

    $lines = implode("\n", SyncActivity::tail());

    expect($lines)->toContain('Sync started.')
        ->toContain('deck: 0 to upload, 0 to download.')
        ->toContain('Sync complete.');

    // The next run replaces the feed instead of appending to history.
    fakeSync();
    app(SyncRunner::class)->run(full: true);

    $feed = SyncActivity::tail();

    expect(implode("\n", $feed))->toContain('Sync started (full reconcile).')
        ->and(collect($feed)->filter(fn ($l) => str_contains($l, 'Sync complete.')))->toHaveCount(1);
});

it('retries a locked-database import and finishes the run instead of aborting', function () {
    $original = syncTestMatch();
    $token = $original->token;
    $bundle = app(MatchBundleBuilder::class)->build($original->fresh());
    $hash = CanonicalJson::hash($bundle);
    $gzip = gzencode(CanonicalJson::encode($bundle), 6);

    wipeMatchLocally($original);

    fakeSync(
        manifestByType: ['match' => [['upload' => [], 'download' => [$token], 'tombstones' => []]]],
        fetchResponse: Http::response(['blobs' => [
            ['client_id' => $token, 'hash' => $hash, 'data' => base64_encode($gzip)],
        ]]),
    );

    // First two attempts hit the classic SQLite write-lock upgrade error,
    // the third succeeds: the run must ride it out, not abort.
    $attempts = 0;
    $real = app(MatchBundleImporter::class);
    $mock = Mockery::mock(MatchBundleImporter::class);
    $mock->shouldReceive('import')->andReturnUsing(function (array $bundle, string $hash) use (&$attempts, $real) {
        if (++$attempts < 3) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked');
        }

        $real->import($bundle, $hash);
    });
    app()->instance(MatchBundleImporter::class, $mock);

    app(SyncRunner::class)->run();

    expect($attempts)->toBe(3)
        ->and(MtgoMatch::where('token', $token)->exists())->toBeTrue()
        ->and(AppSettings::syncLastError())->toBeNull();
});

it('skips a blob whose import never gets the lock and still completes the run', function () {
    $original = syncTestMatch();
    $token = $original->token;
    $bundle = app(MatchBundleBuilder::class)->build($original->fresh());
    $hash = CanonicalJson::hash($bundle);
    $gzip = gzencode(CanonicalJson::encode($bundle), 6);

    wipeMatchLocally($original);

    fakeSync(
        manifestByType: ['match' => [['upload' => [], 'download' => [$token], 'tombstones' => []]]],
        fetchResponse: Http::response(['blobs' => [
            ['client_id' => $token, 'hash' => $hash, 'data' => base64_encode($gzip)],
        ]]),
    );

    $mock = Mockery::mock(MatchBundleImporter::class);
    $mock->shouldReceive('import')->andThrow(new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked'));
    app()->instance(MatchBundleImporter::class, $mock);

    app(SyncRunner::class)->run();

    expect(MtgoMatch::where('token', $token)->exists())->toBeFalse()
        ->and(AppSettings::syncLastError())->toBeNull()
        ->and(implode("\n", SyncActivity::tail()))->toContain('left for the next run');
});

it('raises one toast for a run that moved matches and stays quiet otherwise', function () {
    Event::fake([AppNotification::class]);

    $original = syncTestMatch();
    $token = $original->token;
    $bundle = app(MatchBundleBuilder::class)->build($original->fresh());
    $hash = CanonicalJson::hash($bundle);
    $gzip = gzencode(CanonicalJson::encode($bundle), 6);

    wipeMatchLocally($original);

    fakeSync(
        manifestByType: ['match' => [['upload' => [], 'download' => [$token], 'tombstones' => []]]],
        fetchResponse: Http::response(['blobs' => [
            ['client_id' => $token, 'hash' => $hash, 'data' => base64_encode($gzip)],
        ]]),
    );

    app(SyncRunner::class)->run();

    Event::assertDispatched(
        AppNotification::class,
        fn ($event) => $event->type === 'sync' && $event->message === '1 match downloaded',
    );

    // A run that moved nothing must not toast.
    Event::fake([AppNotification::class]);
    fakeSync();
    app(SyncRunner::class)->run();

    Event::assertNotDispatched(AppNotification::class);
});

it('only offers matches and leagues of enabled decks as dirty or known, decks regardless', function () {
    $on = syncTestMatch();
    $on->deckVersion->deck->update(['cloud_sync_enabled' => true]);
    $off = syncTestMatch();
    $off->deckVersion->deck->update(['cloud_sync_enabled' => false]);
    $none = syncTestMatch(['deck_version_id' => null]);

    fakeSync();

    app(SyncRunner::class)->run();

    $final = manifestRequestsFor('match')->last();
    $known = $final['known'];
    $manifests = manifestRequestsFor('match');
    $allDirty = $manifests->flatMap(fn ($request) => collect($request['dirty'])->pluck('client_id'))->all();

    expect($allDirty)->toBe([$on->token])
        ->and($known)->toBe([$on->token])
        ->and(manifestRequestsFor('deck')->last()['known'])->toHaveCount(3)
        // The fixture's three leagues hang off no deck at all, so the gate
        // must keep every one of them out of the league manifest.
        ->and(manifestRequestsFor('league')->last()['known'])->toBe([])
        ->and(manifestRequestsFor('league')->flatMap(fn ($request) => $request['dirty'])->all())->toBe([]);
});

it('applies the manifest slots to local deck flags', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => false]);

    fakeSync(manifestByType: ['deck' => [[
        'upload' => [], 'download' => [], 'tombstones' => [],
        'slots' => ['limit' => 1, 'used' => 1, 'decks' => [['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null]]],
    ]]]);

    app(SyncRunner::class)->run();

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeTrue()
        ->and(AppSettings::syncSlots()['used'])->toBe(1);
});

it('ignores a slots payload with no decks key, leaving flags and the stored ledger alone', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => true]);
    $stored = ['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]];
    AppSettings::setSyncSlots($stored);

    fakeSync(manifestByType: ['deck' => [[
        'upload' => [], 'download' => [], 'tombstones' => [],
        'slots' => ['limit' => 1, 'used' => 1],
    ]]]);

    app(SyncRunner::class)->run();

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeTrue()
        ->and(AppSettings::syncSlots())->toBe($stored);
});

it('applies an empty decks ledger, which turns every deck off', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => true]);

    fakeSync(manifestByType: ['deck' => [[
        'upload' => [], 'download' => [], 'tombstones' => [],
        'slots' => ['limit' => 1, 'used' => 0, 'decks' => []],
    ]]]);

    app(SyncRunner::class)->run();

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeFalse()
        ->and(AppSettings::syncSlots()['used'])->toBe(0);
});

it('hands back the slot of a deleted deck the server still lists as enabled, once per run', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => true]);
    $deck->delete();

    fakeSync(manifestByType: ['deck' => [[
        'upload' => [], 'download' => [], 'tombstones' => [],
        'slots' => ['limit' => 1, 'used' => 1, 'decks' => [['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null]]],
    ]]]);
    Http::fake(['*/api/sync/decks/*' => Http::response(['limit' => 1, 'used' => 0, 'decks' => []])]);

    app(SyncRunner::class)->run();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://mymtgo.com/api/sync/decks/111'
        && $request['enabled'] === false);

    // Three types share one run, and the ledger rides every manifest: the
    // disable must still go out exactly once.
    expect(collect(Http::recorded(fn (Request $request) => $request->method() === 'PUT')))->toHaveCount(1)
        ->and((bool) Deck::withTrashed()->findOrFail($deck->id)->cloud_sync_enabled)->toBeFalse()
        ->and(AppSettings::syncSlots()['used'])->toBe(0);
});

it('releases a deleted limited deck\'s slot by deriving kind from the client id, not defaulting to constructed', function () {
    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited', 'cloud_sync_enabled' => true]);
    $deck->delete();

    fakeSync(manifestByType: ['deck' => [[
        'upload' => [], 'download' => [], 'tombstones' => [],
        'slots' => ['limit' => null, 'used' => 1, 'decks' => [['client_id' => 'limited_abc', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null]]],
    ]]]);
    Http::fake(['*/api/sync/decks/*' => Http::response(['limit' => null, 'used' => 0, 'decks' => []])]);

    app(SyncRunner::class)->run();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://mymtgo.com/api/sync/decks/limited_abc'
        && $request['enabled'] === false
        && $request['kind'] === 'limited');

    expect((bool) Deck::withTrashed()->findOrFail($deck->id)->cloud_sync_enabled)->toBeFalse();
});

it('carries on with the run when the slot release fails', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => true]);
    $deck->delete();
    $match = syncTestMatch();
    $liveClientId = DeckClientId::for((string) $match->deckVersion->deck->mtgo_id);

    fakeSync(manifestByType: ['deck' => [[
        'upload' => [], 'download' => [], 'tombstones' => [],
        'slots' => ['limit' => 2, 'used' => 2, 'decks' => [
            ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
            ['client_id' => $liveClientId, 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
        ]],
    ]]]);
    Http::fake(['*/api/sync/decks/*' => Http::response(['message' => 'Server Error'], 500)]);

    app(SyncRunner::class)->run();

    expect(AppSettings::syncLastError())->toBeNull()
        ->and(implode("\n", SyncActivity::tail()))->toContain('Sync complete.')
        // The failed release must not strand the deck locally either.
        ->and((bool) Deck::withTrashed()->findOrFail($deck->id)->cloud_sync_enabled)->toBeFalse()
        // The rest of the run still happened: the match type reached its
        // own manifest with the dirty row.
        ->and(manifestRequestsFor('match')->flatMap(fn ($request) => collect($request['dirty'])->pluck('client_id'))->all())
        ->toBe([$match->token]);
});

it('confirms every account with a login id at the end of a run', function () {
    Account::factory()->create(['username' => 'keyed', 'login_id' => 3022021]);
    Account::factory()->create(['username' => 'unkeyed', 'login_id' => null]);
    fakeSync();
    Http::fake(['*/api/account/players' => Http::response(['id' => 1, 'username' => 'keyed', 'login_id' => 3022021, 'public' => false])]);

    app(SyncRunner::class)->run();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/account/players') && $request['login_id'] === 3022021);

    expect(AppSettings::syncAttested())->toBe([3022021 => 'keyed'])
        ->and(implode("\n", SyncActivity::tail()))->toContain('Confirmed 1 MTGO account.');
});

it('does not re-post an account already confirmed', function () {
    Account::factory()->create(['username' => 'keyed', 'login_id' => 3022021]);
    AppSettings::setSyncAttested([3022021 => 'keyed']);
    fakeSync();

    app(SyncRunner::class)->run();

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/account/players'));
    expect(implode("\n", SyncActivity::tail()))->not->toContain('Confirmed');
});

it('completes the run and leaves the account owed when confirming fails', function () {
    Sleep::fake();
    Account::factory()->create(['username' => 'keyed', 'login_id' => 3022021]);
    fakeSync();
    Http::fake(['*/api/account/players' => Http::response(['message' => 'Server Error'], 500)]);

    app(SyncRunner::class)->run();

    expect(AppSettings::syncLastError())->toBeNull()
        ->and(AppSettings::syncAttested())->toBe([])
        ->and(implode("\n", SyncActivity::tail()))->toContain('Sync complete.')
        ->and(implode("\n", SyncActivity::tail()))->toContain('Could not confirm 1 MTGO account, retrying next run.');
});
