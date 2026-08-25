<?php

use App\Actions\Drafts\AdoptCourselessDraftLeague;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The league ResolveDraftLeague minted from the draft lines: it owns the
 * CourseID and the draft, and has never held a match.
 *
 * @param  array<int, int>  $pool
 */
function mintedDraftRun(array $pool): Draft
{
    $league = League::factory()->create([
        'event_id' => 11039,
        'mtgo_course_id' => 35746768,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
        'started_at' => now()->subHours(3),
    ]);

    $draft = Draft::factory()->for($league)->finished()->create(['started_at' => now()->subHours(3)]);

    foreach (array_values($pool) as $index => $catalogId) {
        DraftPick::factory()->for($draft)->create([
            'ordinal' => $index + 1,
            'picked_catalog_id' => $catalogId,
        ]);
    }

    return $draft;
}

/**
 * The league AssignLeague minted for the run's matches while the app was not
 * watching the draft: real token, no CourseID, no draft.
 *
 * @param  array<int, int>  $registeredMainDeck
 */
function courselessMatchRun(array $registeredMainDeck): League
{
    $league = League::factory()->create([
        'event_id' => 11039,
        'mtgo_course_id' => null,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
        'started_at' => now()->subHours(2),
    ]);

    MtgoMatch::factory()->create(['league_id' => $league->id, 'started_at' => now()->subHours(2)]);

    $league->deckSnapshots()->create([
        'source' => 'registered',
        'cards' => array_map(fn (int $id): array => [
            'catalog_id' => $id, 'quantity' => 1, 'sideboard' => false,
        ], $registeredMainDeck),
        'signature' => 'sig',
        'captured_at' => now()->subHours(2),
    ]);

    return $league;
}

/** @param  array<int, int>  $catalogIds */
function makeResolvedSpells(array $catalogIds): void
{
    foreach ($catalogIds as $catalogId) {
        Card::factory()->create(['mtgo_id' => (string) $catalogId, 'type' => 'Creature']);
    }
}

it('adopts the match-holding league when its registered deck came from the pool', function () {
    makeResolvedSpells(range(1000, 1022));

    $draft = mintedDraftRun(range(1000, 1041));
    $minted = $draft->league;
    $candidate = courselessMatchRun(range(1000, 1022));

    AdoptCourselessDraftLeague::run();

    expect(League::where('event_id', 11039)->count())->toBe(1)
        ->and($draft->fresh()->league_id)->toBe($candidate->id)
        ->and($candidate->fresh()->mtgo_course_id)->toBe(35746768)
        ->and($candidate->fresh()->state)->toBe(LeagueState::Active)
        ->and($candidate->fresh()->matches()->count())->toBe(1)
        ->and(League::withTrashed()->find($minted->id)->trashed())->toBeTrue();
});

it('leaves both leagues alone when the registered deck is a different pool', function () {
    makeResolvedSpells(range(5000, 5022));

    $draft = mintedDraftRun(range(1000, 1041));
    $minted = $draft->league;
    $candidate = courselessMatchRun(range(5000, 5022));

    AdoptCourselessDraftLeague::run();

    expect(League::where('event_id', 11039)->count())->toBe(2)
        ->and($draft->fresh()->league_id)->toBe($minted->id)
        ->and($candidate->fresh()->mtgo_course_id)->toBeNull();
});

it('leaves a league that already holds matches for this draft alone', function () {
    makeResolvedSpells(range(1000, 1022));

    $draft = mintedDraftRun(range(1000, 1041));
    MtgoMatch::factory()->create(['league_id' => $draft->league_id, 'started_at' => now()->subHours(2)]);
    $candidate = courselessMatchRun(range(1000, 1022));

    AdoptCourselessDraftLeague::run();

    expect($draft->fresh()->league_id)->toBe($draft->league_id)
        ->and($candidate->fresh()->mtgo_course_id)->toBeNull()
        ->and(League::where('event_id', 11039)->count())->toBe(2);
});

it('does not adopt a league whose matches started before the draft', function () {
    // A run's matches are always played after its draft, so an earlier match
    // means an earlier run, however well the pools happen to overlap.
    makeResolvedSpells(range(1000, 1022));

    $draft = mintedDraftRun(range(1000, 1041));
    $minted = $draft->league;
    $candidate = courselessMatchRun(range(1000, 1022));
    $candidate->matches()->update(['started_at' => $draft->started_at->copy()->subDay()]);

    AdoptCourselessDraftLeague::run();

    expect(League::where('event_id', 11039)->count())->toBe(2)
        ->and($draft->fresh()->league_id)->toBe($minted->id)
        ->and($candidate->fresh()->mtgo_course_id)->toBeNull();
});
