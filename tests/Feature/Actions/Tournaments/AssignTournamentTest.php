<?php

use App\Actions\Tournaments\AssignTournament;
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

    Http::fake([
        '*/api/tournaments/12841367' => Http::response([
            'mtgo_event_id' => 12841367,
            'name' => 'Legacy Challenge 32',
            'format' => 'CLEGACY',
            'started_at' => '2026-05-03T05:00:00.000000Z',
        ], 200),
        '*' => Http::response([], 200),
    ]);
});

it('creates a tournament and links the match using API metadata', function () {
    $version = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'tournament_token' => '509e5c65-2819-43e6-b6eb-1dda409ee9e2',
        'tournament_round' => 1,
        'deck_version_id' => $version->id,
        'started_at' => '2026-05-03 11:00:00',
    ]);

    AssignTournament::run($match);

    $tournament = Tournament::sole();
    expect($tournament)
        ->mtgo_event_id->toBe(12841367)
        ->name->toBe('Legacy Challenge 32')
        ->format->toBe('CLEGACY')
        ->name_synthesized->toBeFalse();

    expect($match->fresh()->tournament_id)->toBe($tournament->id);
});

it('is a no-op when match already linked to a tournament', function () {
    Http::preventStrayRequests();

    $existing = Tournament::factory()->create();
    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'tournament_id' => $existing->id,
    ]);

    AssignTournament::run($match);

    expect(Tournament::count())->toBe(1);
    expect($match->fresh()->tournament_id)->toBe($existing->id);
});

it('is a no-op when match has no tournament_event_id', function () {
    Http::preventStrayRequests();

    $match = MtgoMatch::factory()->create(['tournament_event_id' => null]);

    AssignTournament::run($match);

    expect(Tournament::count())->toBe(0);
    expect($match->fresh()->tournament_id)->toBeNull();
});

it('reuses an existing tournament keyed by mtgo_event_id', function () {
    Http::preventStrayRequests();

    $version = DeckVersion::factory()->create();
    $existing = Tournament::factory()->create([
        'mtgo_event_id' => 12841367,
    ]);
    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'deck_version_id' => $version->id,
    ]);

    AssignTournament::run($match);

    expect(Tournament::count())->toBe(1);
    expect($match->fresh()->tournament_id)->toBe($existing->id);
});

it('reuses one tournament across matches that ran on different deck versions', function () {
    Http::preventStrayRequests();

    $v1 = DeckVersion::factory()->create();
    $v2 = DeckVersion::factory()->create();
    $existing = Tournament::factory()->create(['mtgo_event_id' => 12841367]);

    $matchA = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'deck_version_id' => $v1->id,
    ]);
    $matchB = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841367,
        'deck_version_id' => $v2->id,
    ]);

    AssignTournament::run($matchA);
    AssignTournament::run($matchB);

    expect(Tournament::count())->toBe(1);
    expect($matchA->fresh()->tournament_id)->toBe($existing->id);
    expect($matchB->fresh()->tournament_id)->toBe($existing->id);
});

it('synthesizes a fallback name when API lookup fails', function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/tournaments/*' => Http::response([], 404),
        '*' => Http::response([], 200),
    ]);

    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 12841999,
        'started_at' => '2026-05-03 11:00:00',
    ]);

    AssignTournament::run($match);

    $tournament = Tournament::sole();
    expect($tournament)
        ->name_synthesized->toBeTrue()
        ->name->toContain('12841999')
        ->format->toBeNull();
});
