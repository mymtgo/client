<?php

use App\Actions\Matches\BuildMatches;
use App\Enums\MatchState;
use App\Models\LogCursor;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not create matches for foreign match tokens without join events', function () {
    LogCursor::create([
        'file_path' => '/test/log',
        'byte_offset' => 0,
        'local_username' => 'TestPlayer',
    ]);

    LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 100,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'SomeRandomState',
        'raw_text' => 'foreign match event',
        'event_type' => 'match_state_changed',
        'logged_at' => now(),
        'ingested_at' => now(),
        'match_id' => 55555,
        'match_token' => 'foreign-token',
    ]);

    BuildMatches::run();

    expect(MtgoMatch::count())->toBe(0);
});

it('filters complete vs incomplete matches correctly via scopes', function () {
    MtgoMatch::create([
        'mtgo_id' => 11111,
        'token' => 'complete-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Complete,
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => 22222,
        'token' => 'started-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Started,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => 33333,
        'token' => 'in-progress-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::InProgress,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => 44444,
        'token' => 'ended-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Ended,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    expect(MtgoMatch::complete()->count())->toBe(1);
    expect(MtgoMatch::incomplete()->count())->toBe(3);
    expect(MtgoMatch::count())->toBe(4);
});

it('includes state filter in submittable scope', function () {
    MtgoMatch::create([
        'mtgo_id' => 99999,
        'token' => 'incomplete-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    expect(MtgoMatch::submittable()->count())->toBe(0);
});
