<?php

use App\Actions\Tournaments\FetchTournamentMetadata;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// Clear the global Http::fake() from Pest.php beforeEach so test-specific
// fakes take priority. Re-add a catch-all '*' in each test's fake array
// to keep NativePHP facades happy.
beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('returns metadata when API responds 200', function () {
    Http::fake([
        '*/api/tournaments/12841367' => Http::response([
            'mtgo_event_id' => 12841367,
            'name' => 'Legacy Challenge 32',
            'format' => 'CLEGACY',
            'started_at' => '2026-05-03T05:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $result = FetchTournamentMetadata::run(12841367);

    expect($result)->toMatchArray([
        'name' => 'Legacy Challenge 32',
        'format' => 'CLEGACY',
    ]);
    expect($result['started_at'])->toBeInstanceOf(Carbon::class);
});

it('returns null on 404', function () {
    Http::fake([
        '*/api/tournaments/*' => Http::response([], 404),
        '*' => Http::response([], 200),
    ]);

    expect(FetchTournamentMetadata::run(99999999))->toBeNull();
});

it('returns null when request throws', function () {
    Http::fake(fn () => throw new RuntimeException('network down'));

    expect(FetchTournamentMetadata::run(12841367))->toBeNull();
});
