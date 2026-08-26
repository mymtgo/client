<?php

use App\Facades\AppSettings;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    mockMtgoManagerForPipeline();

    /**
     * Both fixtures were recorded on a UTC+1 machine: their line prefixes read
     * 12:12:00 where the JSON payload on the same line says 11:12:00Z. The
     * default test timezone is UTC, which would push every derived shown_at an
     * hour past its own deadline and turn the pick timings into nonsense.
     * Pinning the timezone to the one the logs were written in is what makes
     * the rendered analytics on these pages real rather than skewed.
     */
    AppSettings::setSystemTimezone('Europe/London');
});

it('renders every limited page for the hobbit fixture with real data', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');
    runPipelineUntilIdle();

    $league = League::where('event_id', 11039)->firstOrFail();

    $this->get(route('limited.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Index')
        ->has('rows', 1)
        ->where('rows.0.leagueId', $league->id)
        ->where('rows.0.setCode', 'HOB')
        ->where('rows.0.kind', 'draft')
        ->where('rows.0.title', 'HOB Draft')
        ->where('rows.0.picksMade', 42)
        ->where('rows.0.picksExpected', 42)
        ->where('rows.0.deckRegistered', true)
        ->where('rows.0.versionCount', 2)
        ->where('rows.0.linked', true)
        ->where('rows.0.wins', 1)
        ->where('rows.0.losses', 2)
        ->where('rows.0.results', ['L', 'W', 'L'])
        ->where('rows.0.opponents', ['Manuel_Danninger', 'asWerty', 'Doome'])
        /**
         * A single hobbit ingest leaves the league Active: nothing in the log
         * ends the run, and only a second entry for event 11039 completes the
         * first one (see DraftLeaguePipelineTest). LeagueStateBadge maps
         * Active to the default badge, so the brief's "Complete" is wrong for
         * a lone ingest.
         */
        ->where('rows.0.state', 'Active')
        ->where('rows.0.stateVariant', 'default')
        ->where('kpis.events', 1)
        ->where('kpis.drafts', 1)
        ->where('kpis.unlinked', 0)
        ->where('kpis.matchWins', 1)
        ->where('kpis.matchLosses', 2)
        ->where('kpis.matchWinPct', 33)
        ->where('kpis.mostDraftedSet', 'HOB')
        ->where('sets', ['HOB']));

    $this->get(route('limited.draft', ['league' => $league->id]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Draft')
        ->where('currentPage', 'draft')
        ->where('event.setCode', 'HOB')
        ->where('event.seatIndex', 7)
        ->where('event.draftState', 'finished')
        ->where('selectedOrdinal', 1)
        ->missing('crossDraft')
        ->has('review.picks', 42)
        ->where('review.header.seatIndex', 7)
        ->where('review.header.seatCount', 8)
        ->where('review.header.packSize', 14)
        ->where('review.header.picksMade', 42)
        ->where('review.header.picksExpected', 42)
        ->where('review.header.state', 'finished')
        ->where('review.picks.0.label', 'P1p1')
        ->where('review.picks.0.packNumber', 1)
        ->where('review.picks.0.pickNumber', 1)
        ->where('review.picks.0.pickedCatalogId', 154228)
        ->has('review.picks.0.available', 14)
        ->where('review.picks.0.wheelReturnOrdinal', 9)
        ->has('review.picks.0.wheeledIds', 6)
        ->has('review.picks.0.takenIds', 7)
        /** Real pick timing: shown 11:12:00Z, picked 11:12:26Z, deadline 11:13:09Z. */
        ->where('review.picks.0.elapsedSeconds', 26)
        ->where('review.picks.0.marginSeconds', 43)
        ->where('review.picks.0.indecisive', true)
        ->where('review.picks.41.label', 'P3p14')
        ->has('review.signals', 5)
        ->has('review.seenWheel')
        ->has('review.cards'));

    $this->get(route('limited.deck', ['league' => $league->id]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Deck')
        ->where('currentPage', 'deck')
        ->missing('evolution'));

    $deck = inertiaPartial(route('limited.deck', ['league' => $league->id]), 'limited/Deck', ['evolution']);
    $deck->assertOk();

    expect($deck->json('props.evolution.summary.versionCount'))->toBe(2)
        ->and($deck->json('props.evolution.summary.drafted'))->toBe(42)
        ->and($deck->json('props.evolution.summary.mainSpells'))->toBe(40)
        ->and($deck->json('props.evolution.summary.sideboard'))->toBe(20)
        ->and($deck->json('props.evolution.versions'))->toHaveCount(2)
        ->and($deck->json('props.evolution.versions.0.matchLabels'))->toBe(['Match 1'])
        ->and($deck->json('props.evolution.versions.0.isCurrent'))->toBeFalse()
        /** Match 3 replays match 2's registered list, so version 2 covers both. */
        ->and($deck->json('props.evolution.versions.1.matchLabels'))->toBe(['Match 2', 'Match 3'])
        ->and($deck->json('props.evolution.versions.1.isCurrent'))->toBeTrue()
        /** The one real change between the two registered lists in this log. */
        ->and($deck->json('props.evolution.versions.1.diffMain.added'))->toBe([
            ['catalogId' => 154028, 'quantity' => 1],
            ['catalogId' => 154228, 'quantity' => 2],
        ])
        ->and($deck->json('props.evolution.versions.1.diffMain.removed'))->toBe([
            ['catalogId' => 153894, 'quantity' => 1],
            ['catalogId' => 153900, 'quantity' => 1],
            ['catalogId' => 154000, 'quantity' => 1],
        ])
        ->and($deck->json('props.evolution.games'))->toHaveCount(3)
        ->and($deck->json('props.evolution.games.0.label'))->toBe('Match 1')
        ->and($deck->json('props.evolution.games.0.opponentName'))->toBe('Manuel_Danninger')
        ->and($deck->json('props.evolution.games.0.result'))->toBe('L')
        ->and($deck->json('props.evolution.games.0.games.0.note'))->toBe('registered deck')
        ->and($deck->json('props.evolution.games.0.games.1.note'))->toBe('no changes')
        ->and($deck->json('props.evolution.pool.groups'))->not->toBeEmpty();

    $this->get(route('limited.matches', ['league' => $league->id]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Matches')
        ->where('currentPage', 'matches')
        ->has('matches', 3)
        ->where('kpis.wins', 1)
        ->where('kpis.losses', 2)
        ->where('kpis.gameWins', 1)
        ->where('kpis.gameLosses', 3));

    $this->get(route('limited.cards', ['league' => $league->id]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Cards')
        ->where('currentPage', 'cards')
        ->missing('table'));

    $cards = inertiaPartial(route('limited.cards', ['league' => $league->id]), 'limited/Cards', ['table']);
    $cards->assertOk();

    expect($cards->json('props.table.summary.distinct'))->toBe(30)
        ->and($cards->json('props.table.summary.games'))->toBe(4)
        ->and($cards->json('props.table.summary.otherDrafts'))->toBe(0)
        ->and($cards->json('props.table.rows'))->toHaveCount(30)
        /** The P1p1 pick wheeled back into pack 2 and was taken again. */
        ->and($cards->json('props.table.rows.0.catalogId'))->toBe(154228)
        ->and($cards->json('props.table.rows.0.labels'))->toBe(['P1p1', 'P2p7'])
        ->and($cards->json('props.table.rows.0.ordinals'))->toBe([1, 21])
        ->and($cards->json('props.table.rows.0.status'))->toBe('main')
        ->and($cards->json('props.table.cards'))->not->toBeEmpty();
});

it('renders every limited page for the incomplete fixture', function () {
    ingestFixtureLog('mtgo_draft_incomplete.log', '2026-07-09');
    runPipelineUntilIdle();

    $league = League::where('event_id', 10814)->firstOrFail();

    $this->get(route('limited.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Index')
        ->has('rows', 1)
        ->where('rows.0.setCode', 'MSH')
        ->where('rows.0.picksMade', 42)
        ->where('rows.0.deckRegistered', false)
        ->where('rows.0.versionCount', 0)
        ->where('rows.0.results', [null, null, null])
        ->where('rows.0.opponents', [])
        /**
         * The draft finished but the league never ended, so it stays Active.
         * "No matches" is only reached once the league completes with nothing
         * played, which this log never does.
         */
        ->where('rows.0.state', 'Active')
        ->where('rows.0.stateVariant', 'default')
        ->where('rows.0.note', null)
        ->where('kpis.events', 1)
        ->where('kpis.matchWinPct', null));

    $this->get(route('limited.draft', ['league' => $league->id]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Draft')
        ->where('event.setCode', 'MSH')
        ->has('review.picks', 42)
        ->where('review.header.seatIndex', 3)
        ->where('review.header.seatCount', 8)
        ->where('review.header.state', 'finished')
        ->where('review.picks.0.label', 'P1p1')
        ->has('review.signals', 5));

    $deck = inertiaPartial(route('limited.deck', ['league' => $league->id]), 'limited/Deck', ['evolution']);
    $deck->assertOk();

    expect($deck->json('props.evolution.summary.versionCount'))->toBe(0)
        ->and($deck->json('props.evolution.summary.drafted'))->toBe(42)
        ->and($deck->json('props.evolution.versions'))->toBe([])
        ->and($deck->json('props.evolution.games'))->toBe([])
        ->and($deck->json('props.evolution.pool.groups'))->not->toBeEmpty()
        /** With no registered deck there is nothing to be in or out of. */
        ->and($deck->json('props.evolution.pool.groups.0.cards.0.status'))->toBe('pool');

    $this->get(route('limited.matches', ['league' => $league->id]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('limited/Matches')
        ->has('matches', 0)
        ->where('kpis.wins', 0)
        ->where('kpis.losses', 0));

    $cards = inertiaPartial(route('limited.cards', ['league' => $league->id]), 'limited/Cards', ['table']);
    $cards->assertOk();

    expect($cards->json('props.table.summary.games'))->toBe(0)
        ->and($cards->json('props.table.summary.distinct'))->toBe(39)
        ->and($cards->json('props.table.rows'))->toHaveCount(39)
        ->and($cards->json('props.table.rows.0.labels'))->toBe(['P1p1'])
        ->and($cards->json('props.table.rows.0.status'))->toBe('pool');
});
