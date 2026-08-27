<?php

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Jobs\SubmitMatch;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches enrichment jobs when match state changes to Complete', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    Queue::assertPushed(SubmitMatch::class);
    Queue::assertPushed(ComputeCardGameStats::class);
    Queue::assertPushed(DetermineMatchArchetypesJob::class);
});

it('dispatches AppNotification when match completes', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    Event::assertDispatched(AppNotification::class, function (AppNotification $event) {
        return $event->type === 'match_win'
            && str_contains($event->title, 'Match recorded')
            && $event->message === 'Win';
    });
});

it('does not trigger enrichment for non-Complete state changes', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);
    $match->update(['state' => MatchState::InProgress]);

    Queue::assertNotPushed(SubmitMatch::class);
    Queue::assertNotPushed(ComputeCardGameStats::class);
});

it('handles enrichment failures gracefully', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    // DetermineMatchArchetypes::run() will run but exit early (no games).
    // The match should still be Complete regardless.
    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);
    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
    ]);

    expect($match->fresh()->state)->toBe(MatchState::Complete);
});

it('dispatches ComputeCardGameStats when deck_version_id is set on an already-complete match', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'deck_version_id' => null,
    ]);

    Queue::fake();

    $deckVersion = DeckVersion::factory()->create();
    $match->update(['deck_version_id' => $deckVersion->id]);

    Queue::assertPushed(ComputeCardGameStats::class, function ($job) use ($match) {
        return $job->matchId === $match->id;
    });
});

it('dispatches ComputeCardGameStats when deck_version_id is swapped on a complete match', function () {
    Event::fake([AppNotification::class]);

    $deckA = DeckVersion::factory()->create();
    $deckB = DeckVersion::factory()->create();

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'deck_version_id' => $deckA->id,
    ]);

    Queue::fake();

    $match->update(['deck_version_id' => $deckB->id]);

    Queue::assertPushed(ComputeCardGameStats::class, function ($job) use ($match) {
        return $job->matchId === $match->id;
    });
});

it('does not dispatch ComputeCardGameStats when deck_version_id is set on a non-complete match', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $deckVersion = DeckVersion::factory()->create();
    $match->update(['deck_version_id' => $deckVersion->id]);

    Queue::assertNotPushed(ComputeCardGameStats::class);
});

it('dispatches ComputeCardGameStats exactly once when state and deck_version_id change together', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $deckVersion = DeckVersion::factory()->create();

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Ended,
        'deck_version_id' => null,
    ]);

    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'deck_version_id' => $deckVersion->id,
    ]);

    Queue::assertPushed(ComputeCardGameStats::class, 1);
});

it('does not dispatch ComputeCardGameStats when deck_version_id is cleared (unlinked)', function () {
    Event::fake([AppNotification::class]);

    $deckVersion = DeckVersion::factory()->create();

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'deck_version_id' => $deckVersion->id,
    ]);

    Queue::fake();

    $match->update(['deck_version_id' => null]);

    Queue::assertNotPushed(ComputeCardGameStats::class);
});

it('completes a draft league after its third match', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $league = League::factory()->create([
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
    ]);

    MtgoMatch::factory()->count(2)->create([
        'league_id' => $league->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
    ]);

    expect($league->refresh()->state)->toBe(LeagueState::Active);

    $third = MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'state' => MatchState::Ended,
    ]);

    $third->update(['state' => MatchState::Complete, 'outcome' => MatchOutcome::Win]);

    expect($league->refresh()->state)->toBe(LeagueState::Complete);
});

it('leaves a constructed league active after its third match', function () {
    Queue::fake();
    Event::fake([AppNotification::class]);

    $league = League::factory()->create([
        'kind' => LeagueKind::Constructed,
        'state' => LeagueState::Active,
    ]);

    MtgoMatch::factory()->count(2)->create([
        'league_id' => $league->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
    ]);

    $third = MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'state' => MatchState::Ended,
    ]);

    $third->update(['state' => MatchState::Complete, 'outcome' => MatchOutcome::Win]);

    expect($league->refresh()->state)->toBe(LeagueState::Active);
});
