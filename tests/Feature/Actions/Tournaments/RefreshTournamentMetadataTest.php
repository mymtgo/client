<?php

use App\Actions\Tournaments\RefreshTournamentMetadata;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Clear the global Http::fake() from Pest.php beforeEach so test-specific
// fakes take priority. Re-add a catch-all '*' in each test's fake array
// to keep NativePHP facades happy.
beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

/**
 * Helper: clear existing stubs and install a fresh set.
 * Required whenever a test needs to override the global Http::fake().
 */
function setHttpFake(array $stubs): void
{
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
    Http::fake($stubs);
}

it('returns zero counts and makes no HTTP calls when no matches need backfill', function () {
    Http::preventStrayRequests();

    $tournament = Tournament::factory()->create(['name_synthesized' => false]);
    MtgoMatch::factory()->create([
        'tournament_event_id' => $tournament->mtgo_event_id,
        'tournament_id' => $tournament->id,
    ]);

    $result = RefreshTournamentMetadata::run();

    expect($result)->toBe([
        'events_scanned' => 0,
        'tournaments_created' => 0,
        'matches_linked' => 0,
        'events_skipped_api_miss' => 0,
        'events_remaining' => 0,
    ]);
});

it('creates one tournament and links every match for that event id', function () {
    setHttpFake([
        '*/api/tournaments/12841367' => Http::response([
            'mtgo_event_id' => 12841367,
            'name' => 'Legacy Challenge 32',
            'format' => 'CLEGACY',
            'started_at' => '2026-05-03T05:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $version = DeckVersion::factory()->create();
    $matches = MtgoMatch::factory()->count(3)->create([
        'tournament_event_id' => 12841367,
        'tournament_token' => '509e5c65-2819-43e6-b6eb-1dda409ee9e2',
        'deck_version_id' => $version->id,
        'tournament_id' => null,
    ]);

    $result = RefreshTournamentMetadata::run();

    expect($result)->toBe([
        'events_scanned' => 1,
        'tournaments_created' => 1,
        'matches_linked' => 3,
        'events_skipped_api_miss' => 0,
        'events_remaining' => 0,
    ]);

    $tournament = Tournament::sole();
    expect($tournament->name)->toBe('Legacy Challenge 32');
    expect($tournament->format)->toBe('CLEGACY');
    expect($tournament->name_synthesized)->toBeFalse();
    expect($tournament->token)->toBe('509e5c65-2819-43e6-b6eb-1dda409ee9e2');

    foreach ($matches as $match) {
        expect($match->fresh()->tournament_id)->toBe($tournament->id);
    }
});

it('skips the event and leaves matches unlinked when API returns 404', function () {
    setHttpFake([
        '*/api/tournaments/*' => Http::response([], 404),
        '*' => Http::response([], 200),
    ]);

    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841999,
        'tournament_id' => null,
    ]);

    $result = RefreshTournamentMetadata::run();

    expect($result)->toBe([
        'events_scanned' => 1,
        'tournaments_created' => 0,
        'matches_linked' => 0,
        'events_skipped_api_miss' => 1,
        'events_remaining' => 0,
    ]);

    expect(Tournament::count())->toBe(0);
    expect($match->fresh()->tournament_id)->toBeNull();
});

it('creates two separate tournaments for two distinct mtgo_event_ids', function () {
    setHttpFake([
        '*/api/tournaments/12841001' => Http::response([
            'mtgo_event_id' => 12841001,
            'name' => 'Modern Challenge 64',
            'format' => 'CMODERN',
            'started_at' => '2026-05-01T14:00:00.000000Z',
        ], 200),
        '*/api/tournaments/12841002' => Http::response([
            'mtgo_event_id' => 12841002,
            'name' => 'Pioneer Challenge 32',
            'format' => 'CPIONEER',
            'started_at' => '2026-05-02T14:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $v1 = DeckVersion::factory()->create();
    $v2 = DeckVersion::factory()->create();

    $match1 = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841001,
        'deck_version_id' => $v1->id,
        'tournament_id' => null,
    ]);
    $match2 = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841002,
        'deck_version_id' => $v2->id,
        'tournament_id' => null,
    ]);

    $result = RefreshTournamentMetadata::run();

    expect($result)->toBe([
        'events_scanned' => 2,
        'tournaments_created' => 2,
        'matches_linked' => 2,
        'events_skipped_api_miss' => 0,
        'events_remaining' => 0,
    ]);

    expect(Tournament::count())->toBe(2);

    expect($match1->fresh()->tournament_id)->not->toBeNull();
    expect($match2->fresh()->tournament_id)->not->toBeNull();
    expect($match1->fresh()->tournament_id)->not->toBe($match2->fresh()->tournament_id);
});

it('links matches to a pre-existing tournament row without creating a duplicate', function () {
    Http::preventStrayRequests();

    $version = DeckVersion::factory()->create();
    $existing = Tournament::factory()->create([
        'mtgo_event_id' => 12841367,
    ]);

    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'deck_version_id' => $version->id,
        'tournament_id' => null,
    ]);

    $result = RefreshTournamentMetadata::run();

    expect($result)->toBe([
        'events_scanned' => 1,
        'tournaments_created' => 0,
        'matches_linked' => 1,
        'events_skipped_api_miss' => 0,
        'events_remaining' => 0,
    ]);

    expect(Tournament::count())->toBe(1);
    expect($match->fresh()->tournament_id)->toBe($existing->id);
});

it('groups matches across multiple decks under one tournament when the event id is shared', function () {
    setHttpFake([
        '*/api/tournaments/12841500' => Http::response([
            'mtgo_event_id' => 12841500,
            'name' => 'Vintage Challenge 32',
            'format' => 'CVINTAGE',
            'started_at' => '2026-04-10T14:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $v1 = DeckVersion::factory()->create();
    $v2 = DeckVersion::factory()->create();

    $matchA = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841500,
        'deck_version_id' => $v1->id,
        'tournament_id' => null,
    ]);
    $matchB = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841500,
        'deck_version_id' => $v2->id,
        'tournament_id' => null,
    ]);

    $result = RefreshTournamentMetadata::run();

    expect($result)->toBe([
        'events_scanned' => 1,
        'tournaments_created' => 1,
        'matches_linked' => 2,
        'events_skipped_api_miss' => 0,
        'events_remaining' => 0,
    ]);

    $tournament = Tournament::sole();
    expect($matchA->fresh()->tournament_id)->toBe($tournament->id);
    expect($matchB->fresh()->tournament_id)->toBe($tournament->id);
});

it('handles matches with null deck_version_id', function () {
    setHttpFake([
        '*/api/tournaments/12841501' => Http::response([
            'mtgo_event_id' => 12841501,
            'name' => 'Vintage Challenge 32',
            'format' => 'CVINTAGE',
            'started_at' => '2026-04-10T14:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841501,
        'deck_version_id' => null,
        'tournament_id' => null,
    ]);

    $result = RefreshTournamentMetadata::run();

    expect($result['tournaments_created'])->toBe(1);
    expect($result['matches_linked'])->toBe(1);

    $tournament = Tournament::sole();
    expect($tournament->name)->toBe('Vintage Challenge 32');
    expect($match->fresh()->tournament_id)->toBe($tournament->id);
});

it('is idempotent — running twice produces no additional tournaments or links', function () {
    setHttpFake([
        '*/api/tournaments/12841367' => Http::response([
            'mtgo_event_id' => 12841367,
            'name' => 'Legacy Challenge 32',
            'format' => 'CLEGACY',
            'started_at' => '2026-05-03T05:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $version = DeckVersion::factory()->create();
    MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'deck_version_id' => $version->id,
        'tournament_id' => null,
    ]);

    RefreshTournamentMetadata::run();

    setHttpFake([
        '*/api/tournaments/12841367' => Http::response([
            'mtgo_event_id' => 12841367,
            'name' => 'Legacy Challenge 32',
            'format' => 'CLEGACY',
            'started_at' => '2026-05-03T05:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $secondResult = RefreshTournamentMetadata::run();

    expect($secondResult)->toBe([
        'events_scanned' => 0,
        'tournaments_created' => 0,
        'matches_linked' => 0,
        'events_skipped_api_miss' => 0,
        'events_remaining' => 0,
    ]);

    expect(Tournament::count())->toBe(1);
});

it('caps API lookups per run and reports remaining events', function () {
    setHttpFake([
        '*/api/tournaments/*' => Http::response([
            'name' => 'Some Tournament',
            'format' => 'CMODERN',
            'started_at' => '2026-05-03T05:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);

    $version = DeckVersion::factory()->create();
    foreach (range(0, 4) as $i) {
        MtgoMatch::factory()->create([
            'tournament_event_id' => 13_000_000 + $i,
            'deck_version_id' => $version->id,
            'tournament_id' => null,
        ]);
    }

    $result = RefreshTournamentMetadata::run(cap: 2);

    expect($result['events_scanned'])->toBe(2);
    expect($result['events_remaining'])->toBe(3);
    expect($result['tournaments_created'])->toBe(2);
});
