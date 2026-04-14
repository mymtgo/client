<?php

use App\Actions\Pipeline\ProcessMatchEvents;
use App\Models\Account;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
