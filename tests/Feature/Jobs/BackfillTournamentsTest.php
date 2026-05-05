<?php

use App\Jobs\BackfillTournaments;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('runs RefreshTournamentMetadata on dispatch', function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/tournaments/12841367' => Http::response([
            'name' => 'Legacy Challenge 32',
            'format' => 'CLEGACY',
            'started_at' => '2026-05-03T05:00:00.000000Z',
        ], 200),
    ]);

    $version = DeckVersion::factory()->create();
    MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'tournament_token' => '509e5c65-2819-43e6-b6eb-1dda409ee9e2',
        'deck_version_id' => $version->id,
    ]);

    (new BackfillTournaments)->handle();

    expect(Tournament::count())->toBe(1);
    expect(Tournament::sole()->name)->toBe('Legacy Challenge 32');
});

it('can be dispatched and queued', function () {
    Bus::fake();

    BackfillTournaments::dispatch();

    Bus::assertDispatched(BackfillTournaments::class);
});
