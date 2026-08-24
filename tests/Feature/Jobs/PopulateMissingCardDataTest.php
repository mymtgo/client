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
