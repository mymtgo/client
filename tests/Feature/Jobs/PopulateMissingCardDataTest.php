<?php

use App\Facades\AppSettings;
use App\Jobs\PopulateMissingCardData;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Drop the global Http::fake() stub from Pest.php so test-specific stubs win.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    AppSettings::setDeviceId('device-populate-test');
});

it('rebuilds the api client per chunk, so a key that expires mid-run still resolves every chunk', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    // 60 stub cards force two /api/cards chunks (chunk size is 50).
    Card::factory()->stub()->count(60)->create();

    $cardCalls = 0;

    Http::fake([
        '*/api/devices/register' => Http::response(['api_key' => 'key-two'], 200),
        '*/api/cards' => function ($request) use (&$cardCalls) {
            $cardCalls++;

            // Simulate the key expiring while the job is mid-run, between
            // the first and second chunk's request.
            if ($cardCalls === 1) {
                AppSettings::setApiKeyExpiresAt(now()->subHour()->toIso8601String());
            }

            $ids = collect($request['ids'] ?? []);

            return Http::response($ids->map(fn ($id) => [
                'value' => $id,
                'scryfall_id' => "scryfall-{$id}",
                'oracle_id' => "oracle-{$id}",
                'name' => "Card {$id}",
                'image' => 'https://example.test/card.png',
            ])->values()->all(), 200);
        },
    ]);

    (new PopulateMissingCardData)->handle();

    expect($cardCalls)->toBe(2)
        ->and(Card::whereNull('scryfall_id')->count())->toBe(0);

    Http::assertSent(fn ($request) => str($request->url())->endsWith('/api/cards')
        && $request->header('X-Api-Key')[0] === 'key-one');

    Http::assertSent(fn ($request) => str($request->url())->endsWith('/api/cards')
        && $request->header('X-Api-Key')[0] === 'key-two');

    Http::assertSent(fn ($request) => str($request->url())->endsWith('/api/devices/register'));
});

it('resolves a back face by retrying the front face catalog id, which sits two below it', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    // MTGO numbers a multi-face printing's back face two above its front
    // face, and the reference API only indexes the front. 126519 is
    // "Boggart Bog", the back of "Boggart Trawler // Boggart Bog" (126517).
    Card::factory()->stub()->create(['mtgo_id' => '126519']);

    Http::fake([
        '*/api/cards' => function ($request) {
            $front = collect($request['ids'] ?? [])->contains(126517);

            return Http::response($front ? [[
                'value' => 126517,
                'scryfall_id' => 'scryfall-boggart',
                'oracle_id' => 'oracle-boggart',
                'name' => 'Boggart Trawler // Boggart Bog',
                'image' => 'https://example.test/boggart.png',
            ]] : [], 200);
        },
    ]);

    (new PopulateMissingCardData)->handle();

    $card = Card::where('mtgo_id', '126519')->sole();

    expect($card->scryfall_id)->toBe('scryfall-boggart')
        ->and($card->name)->toBe('Boggart Trawler // Boggart Bog')
        ->and($card->mtgo_id)->toBe('126519');
});

it('leaves a card alone when the id two below it is a different single-faced card', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    // 16156 is MTGO's "Ice" (half of "Fire // Ice"), but 16154 is an
    // unrelated single-faced card. Accepting it would mislabel the row.
    Card::factory()->stub()->create(['mtgo_id' => '16156']);

    Http::fake([
        '*/api/cards' => function ($request) {
            $front = collect($request['ids'] ?? [])->contains(16154);

            return Http::response($front ? [[
                'value' => 16154,
                'scryfall_id' => 'scryfall-vindicate',
                'oracle_id' => 'oracle-vindicate',
                'name' => 'Vindicate',
                'image' => 'https://example.test/vindicate.png',
            ]] : [], 200);
        },
    ]);

    (new PopulateMissingCardData)->handle();

    expect(Card::where('mtgo_id', '16156')->sole()->scryfall_id)->toBeNull();
});

it('fetches cards missing a scryfall id even when every card already has a name', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    // No nameless stub exists, so the job must not stop before the pass
    // that fills in scryfall data.
    Card::factory()->create(['mtgo_id' => '12345', 'name' => 'Lightning Bolt', 'scryfall_id' => null]);

    Http::fake([
        '*/api/cards' => Http::response([[
            'value' => 12345,
            'scryfall_id' => 'scryfall-bolt',
            'oracle_id' => 'oracle-bolt',
            'name' => 'Lightning Bolt',
            'image' => 'https://example.test/bolt.png',
        ]], 200),
    ]);

    (new PopulateMissingCardData)->handle();

    expect(Card::where('mtgo_id', '12345')->sole()->scryfall_id)->toBe('scryfall-bolt');
});

it('resolves a card the api only knows by name, such as a split card half', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    // MTGO numbers each half of a split card separately and Scryfall holds
    // neither id, so 48556 can only ever be found as the name "Tear".
    Card::factory()->create(['mtgo_id' => '48556', 'name' => 'Tear', 'scryfall_id' => null]);

    Http::fake([
        '*/api/cards' => function ($request) {
            if (! collect($request['names'] ?? [])->contains('Tear')) {
                return Http::response([], 200);
            }

            return Http::response([[
                'query' => 'Tear',
                'value' => null,
                'scryfall_id' => 'scryfall-weartear',
                'oracle_id' => 'oracle-weartear',
                'name' => 'Wear // Tear',
                'image' => 'https://example.test/weartear.png',
            ]], 200);
        },
    ]);

    (new PopulateMissingCardData)->handle();

    $card = Card::where('mtgo_id', '48556')->sole();

    expect($card->scryfall_id)->toBe('scryfall-weartear')
        ->and($card->name)->toBe('Wear // Tear')
        ->and($card->mtgo_id)->toBe('48556');
});

it('does not ask by name for a card that has no name', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    Card::factory()->stub()->create(['mtgo_id' => '99999']);

    Http::fake(['*/api/cards' => Http::response([], 200)]);

    (new PopulateMissingCardData)->handle();

    Http::assertNotSent(fn ($request) => collect($request['names'] ?? [])->isNotEmpty());
});

it('finds a front face one below when the printing has no foil id between them', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    // MTGO allocates a nonfoil id then a foil id, so a back face usually
    // lands two above its front. A set with no foil ids closes that gap and
    // the back face sits one above instead, as in Hobbit.
    Card::factory()->stub()->create(['mtgo_id' => '154069']);

    Http::fake([
        '*/api/cards' => function ($request) {
            $ids = collect($request['ids'] ?? [])->map(fn ($id) => (int) $id);

            // Two below is a different, single-faced card and must be refused.
            if ($ids->contains(154067)) {
                return Http::response([[
                    'value' => 154067,
                    'scryfall_id' => 'scryfall-riddlemaster',
                    'oracle_id' => 'oracle-riddlemaster',
                    'name' => 'Gollum, Riddle Master',
                    'image' => 'https://example.test/riddle.png',
                ]], 200);
            }

            if ($ids->contains(154068)) {
                return Http::response([[
                    'value' => 154068,
                    'scryfall_id' => 'scryfall-slinker',
                    'oracle_id' => 'oracle-slinker',
                    'name' => 'Gollum, Silent Slinker // Meager Meal',
                    'image' => 'https://example.test/slinker.png',
                ]], 200);
            }

            return Http::response([], 200);
        },
    ]);

    (new PopulateMissingCardData)->handle();

    $card = Card::where('mtgo_id', '154069')->sole();

    expect($card->name)->toBe('Gollum, Silent Slinker // Meager Meal')
        ->and($card->scryfall_id)->toBe('scryfall-slinker');
});

it('prefers the front face two below when both neighbours are multi-face', function () {
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    Card::factory()->stub()->create(['mtgo_id' => '126519']);

    Http::fake([
        '*/api/cards' => function ($request) {
            $ids = collect($request['ids'] ?? [])->map(fn ($id) => (int) $id);

            if ($ids->contains(126517)) {
                return Http::response([[
                    'value' => 126517,
                    'scryfall_id' => 'scryfall-nonfoil',
                    'oracle_id' => 'oracle-boggart',
                    'name' => 'Boggart Trawler // Boggart Bog',
                    'image' => 'https://example.test/boggart.png',
                ]], 200);
            }

            return Http::response([], 200);
        },
    ]);

    (new PopulateMissingCardData)->handle();

    expect(Card::where('mtgo_id', '126519')->sole()->scryfall_id)->toBe('scryfall-nonfoil');
});
