<?php

use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => mockMtgoManagerForPipeline());

it('records a registered snapshot for every limited match, even when the deck signature already matched an existing version', function () {
    // Regression for the Task 10 finding: AdvanceMatchState used to gate the
    // entire limited block (snapshot recording plus version linking) behind
    // "! $match->deck_version_id". Match 3 of the hobbit fixture plays the
    // identical 40 cards as match 2, so DetermineMatchDeck's generic
    // deck_used-signature lookup resolves match 3's deck_version_id before
    // the limited block runs, which used to skip RecordRegisteredDeckSnapshot
    // for match 3 entirely even though the match correctly links to the
    // existing DeckVersion. AdvanceMatchState now records a snapshot for
    // every limited match regardless of whether it already has a version.
    ingestFixtureLog('mtgo_draft_hobbit.log');

    runPipelineUntilIdle();

    $league = League::where('event_id', 11039)->firstOrFail();
    $leagueMatches = $league->matches()->orderBy('started_at')->get();

    expect($leagueMatches)->toHaveCount(3)
        ->and($leagueMatches->pluck('deck_version_id')->filter())->toHaveCount(3)
        ->and($league->deckSnapshots()->where('source', 'registered')->count())->toBe(3);
});
