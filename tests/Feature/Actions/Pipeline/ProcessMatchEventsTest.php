<?php

use App\Actions\Pipeline\ProcessMatchEvents;
use App\Enums\MatchState;
use App\Models\Account;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Creates the minimum event set AdvanceMatchState needs to build a match:
 *  - game_management_json event carrying both match_token and match_id
 *  - match_state_changed event with context "MatchJoinedEventUnderwayState"
 *  - game_state_update event carrying the Players JSON blob
 *
 * Username is intentionally nullable on every row so tests can exercise
 * the fallback branch in ProcessMatchEvents::processMatch().
 */
function createMatchEvents(string $token, string $matchId, string $localUsername, ?string $rowUsername = null): void
{
    $playersJson = json_encode([
        'Players' => [
            ['Name' => $localUsername],
            ['Name' => 'Opponent'],
        ],
    ]);

    $base = [
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/test.log',
        'timestamp' => now()->format('H:i:s'),
        'level' => 'Info',
        'ingested_at' => now(),
        'logged_at' => now(),
        'processed_at' => null,
        'username' => $rowUsername,
    ];

    LogEvent::create(array_merge($base, [
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'category' => 'Match',
        'context' => 'MatchGameRoomEvents',
        'raw_text' => '{"match_id":"'.$matchId.'","match_token":"'.$token.'"}',
        'match_id' => $matchId,
        'match_token' => $token,
        'event_type' => 'game_management_json',
    ]));

    LogEvent::create(array_merge($base, [
        'byte_offset_start' => 3,
        'byte_offset_end' => 4,
        'category' => 'Match',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "match joined\nReceiver: MatchGameRoomEventObserver\nPlayFormatCd = Limited\nGameStructureCd = Swiss",
        'match_token' => $token,
        'event_type' => 'match_state_changed',
    ]));

    LogEvent::create(array_merge($base, [
        'byte_offset_start' => 5,
        'byte_offset_end' => 6,
        'category' => 'Match',
        'context' => 'GameStateUpdate',
        'raw_text' => $playersJson,
        'match_id' => $matchId,
        'event_type' => 'game_state_update',
    ]));
}

it('falls back to active account username when log rows have no username', function () {
    Account::create(['username' => 'anticloser', 'active' => true, 'tracked' => true]);

    createMatchEvents(token: 'tok-1', matchId: '100', localUsername: 'anticloser', rowUsername: null);

    ProcessMatchEvents::run();

    expect(MtgoMatch::where('token', 'tok-1')->exists())->toBeTrue();
});

it('prefers log row username over active account fallback', function () {
    Account::create(['username' => 'anticloser', 'active' => true, 'tracked' => true]);
    Account::create(['username' => 'alt_player', 'active' => false, 'tracked' => true]);

    createMatchEvents(token: 'tok-2', matchId: '200', localUsername: 'alt_player', rowUsername: 'alt_player');

    ProcessMatchEvents::run();

    // Match exists because the explicit row username ('alt_player') was used,
    // not the active account ('anticloser'). The phantom-match filter allowed
    // it through because 'alt_player' is in Players[].
    expect(MtgoMatch::where('token', 'tok-2')->exists())->toBeTrue();
});

it('still drops match when no accounts exist at all', function () {
    createMatchEvents(token: 'tok-3', matchId: '300', localUsername: 'anticloser', rowUsername: null);

    // Backdate ingestion so handleMissingUsername() treats events as stale
    LogEvent::where('match_token', 'tok-3')->update(['ingested_at' => now()->subMinutes(3)]);

    ProcessMatchEvents::run();

    expect(MtgoMatch::where('token', 'tok-3')->exists())->toBeFalse();
    // Stale events get marked processed so the pipeline doesn't loop on them forever
    expect(LogEvent::where('match_token', 'tok-3')->whereNull('processed_at')->count())->toBe(0);
});

it('does not create match when sole account is untracked', function () {
    // tracked = false means the user has explicitly opted this account out
    Account::create(['username' => 'untracked_user', 'active' => true, 'tracked' => false]);

    createMatchEvents(token: 'tok-4', matchId: '400', localUsername: 'untracked_user', rowUsername: null);

    ProcessMatchEvents::run();

    expect(MtgoMatch::where('token', 'tok-4')->exists())->toBeFalse();
});

it('drops match as phantom when fallback attributes to wrong multi-account user', function () {
    // Two tracked accounts; 'accountA' is the currently-active one
    Account::create(['username' => 'accountA', 'active' => true, 'tracked' => true]);
    Account::create(['username' => 'accountB', 'active' => false, 'tracked' => true]);

    // But the match actually belongs to accountB — the game-state Players[]
    // only contains accountB and an opponent, so the phantom filter in
    // AdvanceMatchState will reject it once we fall back to accountA.
    createMatchEvents(token: 'tok-phantom', matchId: '500', localUsername: 'accountB', rowUsername: null);

    ProcessMatchEvents::run();

    // Documented trade-off: better to drop than to mis-attribute.
    expect(MtgoMatch::where('token', 'tok-phantom')->exists())->toBeFalse();
});

it('reprocesses an in_progress match whose only unprocessed events are trailing match_state_changed end signals', function () {
    Account::create(['username' => 'alec', 'active' => true, 'tracked' => true]);

    $match = MtgoMatch::create([
        'mtgo_id' => '900',
        'token' => 'tok-orphan',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now(),
        'state' => MatchState::InProgress,
    ]);

    $base = [
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/orphan.log',
        'timestamp' => now()->format('H:i:s'),
        'level' => 'Info',
        'ingested_at' => now(),
        'logged_at' => now(),
        'username' => 'alec',
        'category' => 'Match',
        'match_id' => '900',
        'match_token' => 'tok-orphan',
        'event_type' => 'match_state_changed',
    ];

    // Already-processed join event — satisfies AdvanceMatchState's join gate.
    LogEvent::create(array_merge($base, [
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'context' => 'Match State Changed for tok-orphan from MatchJoinedWaitingForGameToStartState to MatchJoinedEventUnderwayState',
        'raw_text' => '(Game Management|Match State Changed for tok-orphan from MatchJoinedWaitingForGameToStartState to MatchJoinedEventUnderwayState)',
        'processed_at' => now(),
    ]));

    // Trailing end-signal events left UNPROCESSED, with no accompanying
    // unprocessed game_management_json — the orphaned-discovery case.
    LogEvent::create(array_merge($base, [
        'byte_offset_start' => 3,
        'byte_offset_end' => 4,
        'context' => 'Match State Changed for tok-orphan from MatchNotJoinedEventUnderwayState to MatchJoinedCompletedState',
        'raw_text' => '(Game Management|Match State Changed for tok-orphan from MatchNotJoinedEventUnderwayState to MatchJoinedCompletedState)',
        'processed_at' => null,
    ]));

    LogEvent::create(array_merge($base, [
        'byte_offset_start' => 5,
        'byte_offset_end' => 6,
        'context' => 'Match State Changed for tok-orphan from MatchJoinedCompletedState to MatchClosedState',
        'raw_text' => '(Game Management|Match State Changed for tok-orphan from MatchJoinedCompletedState to MatchClosedState)',
        'processed_at' => null,
    ]));

    ProcessMatchEvents::run();

    $match->refresh();

    // Discovery surfaced the orphaned token; AdvanceMatchState advanced it past
    // InProgress on the strength of the MatchClosedState signal.
    expect($match->state)->not->toBe(MatchState::InProgress);
    expect(LogEvent::where('match_token', 'tok-orphan')->whereNull('processed_at')->count())->toBe(0);
});

it('does not consume a retry attempt when commit fails with a readonly-database error', function () {
    Account::create(['username' => 'alec', 'active' => true, 'tracked' => true]);

    // Seed the match with 4 prior attempts so the NEXT failure would trip
    // the 5-strike rule if the error were mis-classified.
    $match = MtgoMatch::create([
        'mtgo_id' => 'readonly-1',
        'token' => 'tok-readonly',
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now(),
        'state' => MatchState::Started,
        'attempts' => 4,
    ]);

    createMatchEvents(token: 'tok-readonly', matchId: 'readonly-1', localUsername: 'alec', rowUsername: 'alec');

    // Fail the first match-table UPDATE AdvanceMatchState issues (the Started
    // → InProgress state bump) by raising a QueryException wrapping a
    // PDOException with SQLite READONLY (native code 8). The match is
    // pre-seeded above, so AdvanceMatchState takes the "find existing"
    // path — no INSERT on matches is expected.
    $thrown = false;
    DB::listen(function ($query) use (&$thrown) {
        if ($thrown) {
            return;
        }

        if (str_starts_with($query->sql, 'update "matches"')) {
            $thrown = true;
            $pdo = new PDOException('SQLSTATE[HY000]: General error: 8 attempt to write a readonly database');
            $pdo->errorInfo = ['HY000', 8, 'attempt to write a readonly database'];
            throw new QueryException(
                connectionName: 'sqlite',
                sql: $query->sql,
                bindings: $query->bindings,
                previous: $pdo,
            );
        }
    });

    ProcessMatchEvents::run();

    $match->refresh();

    // The readonly error must be treated as transient — attempts stays at 4,
    // failed_at stays null, and the match lives to be retried on the next tick.
    expect($match->attempts)->toBe(4);
    expect($match->failed_at)->toBeNull();
});
