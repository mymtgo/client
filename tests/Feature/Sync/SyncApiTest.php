<?php

use App\Exceptions\Sync\NotLinkedException;
use App\Exceptions\Sync\SlotLimitException;
use App\Facades\AppSettings;
use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

// The Feature test suite's global beforeEach registers a blanket Http::fake()
// that matches every URL and wins over any later Http::fake([...]) pattern
// (Laravel evaluates fakes in registration order, first match wins). Reset
// stubCallbacks here so each test's own fake is the only one in play, the
// same reset used in DeviceLinkTest.
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
});

it('sends the manifest body shape with the bearer header', function () {
    Http::fake([
        '*/api/sync/manifest' => Http::response([
            'upload' => ['a'],
            'download' => ['b'],
            'tombstones' => [],
        ]),
    ]);

    $result = app(SyncApi::class)->manifest('match', '2026-01-01T00:00:00Z', ['known-1'], ['dirty-1', 'dirty-2'], true);

    expect($result)->toBe([
        'upload' => ['a'],
        'download' => ['b'],
        'tombstones' => [],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://mymtgo.com/api/sync/manifest'
            && $request->hasHeader('Authorization', 'Bearer access-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request['type'] === 'match'
            && $request['since'] === '2026-01-01T00:00:00Z'
            && $request['known'] === ['known-1']
            && $request['dirty'] === ['dirty-1', 'dirty-2']
            && $request['last'] === true;
    });
});

it('refreshes the token once and retries after a single 401', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 2592000]),
        '*/api/sync/manifest' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['upload' => [], 'download' => [], 'tombstones' => []]),
    ]);

    $result = app(SyncApi::class)->manifest('deck', null, [], [], true);

    expect($result)->toBe(['upload' => [], 'download' => [], 'tombstones' => []])
        ->and(app(SyncTokens::class)->accessToken())->toBe('new-token');

    Http::assertSentCount(3); // manifest (401), oauth/token, manifest (200)

    Http::assertSent(function ($request) {
        return $request->url() === 'https://mymtgo.com/api/sync/manifest'
            && $request->hasHeader('Authorization', 'Bearer new-token');
    });
});

it('throws NotLinked and clears credentials on a second consecutive 401', function () {
    Http::fake([
        '*/oauth/token' => Http::sequence()
            ->push(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 2592000])
            ->push(['error' => 'invalid_grant'], 400),
        '*/api/sync/manifest' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(fn () => app(SyncApi::class)->manifest('league', null, [], [], true))
        ->toThrow(NotLinkedException::class);

    expect(app(SyncTokens::class)->linked())->toBeFalse();
});

it('throws a plain RuntimeException (not NotLinked) on a second 401 when the second refresh succeeds', function () {
    // A second consecutive 401 after a refresh that itself succeeded proves
    // the device is still linked (RefreshAccessToken only clears
    // credentials and throws NotLinked on an invalid_grant response). The
    // persistent 401 should surface as an ordinary request failure, not a
    // NotLinked exception that would drive wrong relink UX.
    Http::fake([
        '*/oauth/token' => Http::sequence()
            ->push(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 2592000])
            ->push(['access_token' => 'newer-token', 'refresh_token' => 'newer-refresh', 'expires_in' => 2592000]),
        '*/api/sync/manifest' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(fn () => app(SyncApi::class)->manifest('league', null, [], [], true))
        ->toThrow(function (RuntimeException $e) {
            expect($e)->not->toBeInstanceOf(NotLinkedException::class)
                ->and($e->getCode())->toBe(401);
        });

    expect(app(SyncTokens::class)->linked())->toBeTrue()
        ->and(app(SyncTokens::class)->accessToken())->toBe('newer-token');
});

it('propagates a non-invalid_grant refresh failure on a second 401 with credentials intact', function () {
    Http::fake([
        '*/oauth/token' => Http::sequence()
            ->push(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 2592000])
            ->push(['error' => 'invalid_request'], 400),
        '*/api/sync/manifest' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(fn () => app(SyncApi::class)->manifest('league', null, [], [], true))
        ->toThrow(RequestException::class);

    expect(app(SyncTokens::class)->linked())->toBeTrue()
        ->and(app(SyncTokens::class)->accessToken())->toBe('new-token')
        ->and(app(SyncTokens::class)->refreshToken())->toBe('new-refresh');
});

it('throws a RuntimeException carrying the status on a 429', function () {
    Http::fake([
        '*/api/sync/manifest' => Http::response(['message' => 'Too Many Requests'], 429),
    ]);

    expect(fn () => app(SyncApi::class)->manifest('match', null, [], [], true))
        ->toThrow(fn (RuntimeException $e) => $e->getCode() === 429);
});

it('throws a RuntimeException carrying the status on a 5xx', function () {
    Http::fake([
        '*/api/sync/manifest' => Http::response(['message' => 'Server Error'], 503),
    ]);

    expect(fn () => app(SyncApi::class)->manifest('match', null, [], [], true))
        ->toThrow(fn (RuntimeException $e) => $e->getCode() === 503);
});

it('throws a RuntimeException carrying the status on a 422 instead of returning the validation body as success', function () {
    // A 422 previously fell through send()'s status checks untouched and
    // was handed back to the caller as an ordinary response: ->json() on a
    // validation error body reads as an empty, successful manifest, so a
    // real shape mismatch (see the dirty-entry-shape tests) shipped
    // invisible instead of throwing.
    Http::fake([
        '*/api/sync/manifest' => Http::response(['message' => 'The dirty field is invalid.', 'errors' => []], 422),
    ]);

    expect(fn () => app(SyncApi::class)->manifest('match', null, [], [], true))
        ->toThrow(fn (RuntimeException $e) => $e->getCode() === 422);
});

it('uploads blobs as multipart carrying meta fields and a file part per blob', function () {
    Http::fake([
        '*/api/sync/blobs' => Http::response(['stored' => ['client-1'], 'rejected' => []]),
    ]);

    $result = app(SyncApi::class)->uploadBlobs('match', [
        [
            'client_id' => 'client-1',
            'hash' => 'hash-1',
            'updated_at' => '2026-01-01T00:00:00Z',
            'sidecar' => ['format' => 'Standard'],
            'gzip' => 'gzipped-bytes',
        ],
    ]);

    expect($result)->toBe(['stored' => ['client-1'], 'rejected' => []]);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://mymtgo.com/api/sync/blobs') {
            return false;
        }

        $hasFile = $request->hasFile('blobs[0]', 'gzipped-bytes', 'client-1.json.gz');

        $body = $request->body();

        return $hasFile
            && str_contains($body, 'name="type"')
            && str_contains($body, 'match')
            && str_contains($body, 'name="meta[0][client_id]"')
            && str_contains($body, 'client-1')
            && str_contains($body, 'name="meta[0][hash]"')
            && str_contains($body, 'hash-1')
            && str_contains($body, 'name="meta[0][updated_at]"')
            && str_contains($body, 'name="meta[0][sidecar][format]"')
            && str_contains($body, 'Standard')
            && str_contains($body, 'client-1.json.gz');
    });
});

it('fetches blobs with the given client ids', function () {
    Http::fake([
        '*/api/sync/blobs/fetch' => Http::response([
            'blobs' => [
                ['client_id' => 'client-1', 'hash' => 'hash-1', 'data' => 'base64data'],
            ],
        ]),
    ]);

    $result = app(SyncApi::class)->fetchBlobs('match', ['client-1', 'client-2']);

    expect($result)->toBe([
        'blobs' => [
            ['client_id' => 'client-1', 'hash' => 'hash-1', 'data' => 'base64data'],
        ],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://mymtgo.com/api/sync/blobs/fetch'
            && $request['type'] === 'match'
            && $request['client_ids'] === ['client-1', 'client-2'];
    });
});

it('reads deck slots', function () {
    Http::fake(['*/api/sync/decks/slots' => Http::response(['limit' => 1, 'used' => 0, 'decks' => []])]);

    expect(app(SyncApi::class)->deckSlots())->toBe(['limit' => 1, 'used' => 0, 'decks' => []]);
});

it('enables a deck and returns the slot summary', function () {
    Http::fake(['*/api/sync/decks/12345' => Http::response(['limit' => 1, 'used' => 1, 'decks' => [['client_id' => '12345', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null]]], 201)]);

    $result = app(SyncApi::class)->setDeckSync('12345', true);

    expect($result['used'])->toBe(1);
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['enabled'] === true);
});

it('derives kind from the client id itself rather than accepting one', function () {
    Http::fake(['*/api/sync/decks/*' => Http::response(['limit' => null, 'used' => 1, 'decks' => []])]);

    app(SyncApi::class)->setDeckSync('12345', true);
    app(SyncApi::class)->setDeckSync('limited_abc123', true);

    $sent = collect(Http::recorded(fn ($request) => $request->method() === 'PUT'))->map(fn (array $pair) => $pair[0]);

    expect($sent[0]['kind'])->toBe('constructed')
        ->and($sent[1]['kind'])->toBe('limited');
});

it('throws SlotLimitException on a slot_limit 422', function () {
    Http::fake(['*/api/sync/decks/222' => Http::response(['error' => 'slot_limit', 'limit' => 1, 'used' => 1, 'frees_at' => '2026-10-10T12:00:00Z'], 422)]);

    try {
        app(SyncApi::class)->setDeckSync('222', true);
        $this->fail('expected SlotLimitException');
    } catch (SlotLimitException $e) {
        expect($e->limit)->toBe(1)->and($e->freesAt)->toBe('2026-10-10T12:00:00Z');
    }
});

it('stores and reads sync slots in app settings', function () {
    AppSettings::setSyncSlots(['limit' => 1, 'used' => 1, 'decks' => []]);

    expect(AppSettings::syncSlots())->toBe(['limit' => 1, 'used' => 1, 'decks' => []]);

    AppSettings::setSyncSlots(null);

    expect(AppSettings::syncSlots())->toBeNull();
});

it('uploads the token catalog gzipped with the bearer header', function () {
    Http::fake(['*/api/cards/token-catalog' => Http::response(['added' => 3, 'already_mapped' => 0, 'tiers' => [], 'unmatched' => []])]);

    $result = app(SyncApi::class)->uploadTokenCatalog(['client_TOK' => '<CardSet/>']);

    expect($result['added'])->toBe(3);

    Http::assertSent(fn ($request) => $request->url() === 'https://mymtgo.com/api/cards/token-catalog'
        && $request->hasHeader('Authorization', 'Bearer access-token')
        && $request->hasHeader('Content-Encoding', 'gzip')
        && json_decode(gzdecode($request->body()), true) === ['files' => ['client_TOK' => '<CardSet/>']]);
});
