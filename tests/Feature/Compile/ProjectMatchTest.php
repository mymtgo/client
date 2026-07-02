<?php

use App\Actions\Compile\ProjectMatch;
use App\Actions\Logs\IngestLogInstance;
use App\Data\ProjectedMatch\MatchData;
use App\Enums\MatchOutcome;
use App\Enums\OutcomeSource;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The real league fixture: local player `anticloser`, one full league match
 * on this token — 2 games (945500844, 945500946) with GsMessage traffic,
 * a LeagueMatchJoinedEventUnderwayState join and a completed terminal state.
 */
const FIXTURE_TOKEN = 'f5e33a1f-c2e7-4678-b30d-309b63f17a40';

function seedFixtureEvents(): void
{
    $path = sys_get_temp_dir().'/project-match-fixture.log';
    copy(base_path('tests/fixtures/mtgo_league_join_drop.log'), $path);

    IngestLogInstance::run($path);
}

it('projects the fixture league match into a MatchData without writing anything', function () {
    seedFixtureEvents();
    $eventCount = LogEvent::count();

    $match = app(ProjectMatch::class)->run(FIXTURE_TOKEN, 'anticloser');

    expect($match)->toBeInstanceOf(MatchData::class);
    expect($match->token)->toBe(FIXTURE_TOKEN);
    expect($match->mtgo_id)->not->toBeNull();
    expect($match->format)->not->toBeNull();
    expect($match->started_at)->not->toBeNull();

    // Projection is pure — the log_events table is read, never written.
    expect(LogEvent::count())->toBe($eventCount);
});

it('builds one GameData per game with MetaMessage-derived data', function () {
    seedFixtureEvents();

    $match = app(ProjectMatch::class)->run(FIXTURE_TOKEN, 'anticloser');

    expect($match->games)->toHaveCount(2);
    expect(collect($match->games)->pluck('mtgo_id')->all())->toBe([945500844, 945500946]);
    expect($match->games[0]->timeline)->not->toBeEmpty();
    // Both game outcomes resolve from the decoded MetaMessage stream.
    expect(collect($match->games)->pluck('won')->all())->each->toBeBool();
});

it('reaches the Complete state and stamps ended_at from the completed signal', function () {
    seedFixtureEvents();

    $match = app(ProjectMatch::class)->run(FIXTURE_TOKEN, 'anticloser');

    expect($match->state)->toBe('Complete');
    expect($match->ended_at)->not->toBeNull();
});

it('leaves the outcome unresolved (the resolver pipeline owns it)', function () {
    seedFixtureEvents();

    $match = app(ProjectMatch::class)->run(FIXTURE_TOKEN, 'anticloser');

    expect($match->outcome)->toBe(MatchOutcome::Unknown);
    expect($match->outcome_source)->toBe(OutcomeSource::Unknown);
});

it('carries the league token and the opponent username', function () {
    seedFixtureEvents();

    $match = app(ProjectMatch::class)->run(FIXTURE_TOKEN, 'anticloser');

    expect($match->league?->token)->not->toBeNull();
    expect($match->opponent->username)->not->toBeNull();
    expect($match->opponent->username)->not->toBe('anticloser');
});

it('returns null for a token with no join event', function () {
    seedFixtureEvents();

    expect(app(ProjectMatch::class)->run('never-seen-token', 'anticloser'))->toBeNull();
});
