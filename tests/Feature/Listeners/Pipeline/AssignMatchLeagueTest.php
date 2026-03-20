<?php

use App\Events\LeagueMatchStarted;
use App\Events\MatchJoined;
use App\Listeners\Pipeline\AssignMatchLeague;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Build a raw_text string that ExtractKeyValueBlock can parse.
 * The parser requires "Receiver:" to appear before the key-value lines.
 */
function buildLeagueRawText(array $meta = []): string
{
    $defaults = [
        'PlayFormatCd' => 'Pmodern',
        'GameStructureCd' => 'Constructed',
    ];

    $meta = array_merge($defaults, $meta);

    $lines = ['12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)', 'Receiver:'];

    foreach ($meta as $key => $value) {
        $lines[] = "{$key} = {$value}";
    }

    return implode("\n", $lines);
}

it('does nothing if match does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-nonexistent',
        'raw_text' => buildLeagueRawText(),
    ]);

    Event::fake([LeagueMatchStarted::class]);

    $listener = new AssignMatchLeague;
    $listener->handle(new MatchJoined($logEvent));

    Event::assertNotDispatched(LeagueMatchStarted::class);
    expect(League::count())->toBe(0);
});

it('does nothing if match already has a league assigned', function () {
    $league = League::factory()->create(['format' => 'Pmodern']);

    $match = MtgoMatch::factory()->create([
        'token' => 'token-already-assigned',
        'format' => 'Pmodern',
        'league_id' => $league->id,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => $match->token,
        'raw_text' => buildLeagueRawText(),
    ]);

    Event::fake([LeagueMatchStarted::class]);

    $listener = new AssignMatchLeague;
    $listener->handle(new MatchJoined($logEvent));

    Event::assertNotDispatched(LeagueMatchStarted::class);
    expect($match->fresh()->league_id)->toBe($league->id);
    expect(League::count())->toBe(1);
});

it('assigns a phantom league and dispatches LeagueMatchStarted when no league token present', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-phantom',
        'format' => 'Pmodern',
        'league_id' => null,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => $match->token,
        'raw_text' => buildLeagueRawText([
            'PlayFormatCd' => 'Pmodern',
            'GameStructureCd' => 'Constructed',
        ]),
    ]);

    Event::fake([LeagueMatchStarted::class]);

    $listener = new AssignMatchLeague;
    $listener->handle(new MatchJoined($logEvent));

    $match->refresh();
    expect($match->league_id)->not->toBeNull();

    $league = League::find($match->league_id);
    expect($league)->not->toBeNull();
    expect((bool) $league->phantom)->toBeTrue();
    expect($league->format)->toBe('Pmodern');

    Event::assertDispatched(LeagueMatchStarted::class);
});

it('assigns a real league by token and dispatches LeagueMatchStarted', function () {
    $leagueToken = 'test-league-token-abc';

    $match = MtgoMatch::factory()->create([
        'token' => 'token-real-league',
        'format' => 'Pmodern',
        'league_id' => null,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => $match->token,
        'raw_text' => buildLeagueRawText([
            'PlayFormatCd' => 'Pmodern',
            'GameStructureCd' => 'Constructed',
            'League Token' => $leagueToken,
        ]),
    ]);

    Event::fake([LeagueMatchStarted::class]);

    $listener = new AssignMatchLeague;
    $listener->handle(new MatchJoined($logEvent));

    $match->refresh();
    expect($match->league_id)->not->toBeNull();

    $league = League::find($match->league_id);
    expect($league)->not->toBeNull();
    expect($league->token)->toBe($leagueToken);
    expect((bool) $league->phantom)->toBeFalse();

    Event::assertDispatched(LeagueMatchStarted::class);
});

it('is idempotent — second call does not reassign when league already set', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-idempotent',
        'format' => 'Pmodern',
        'league_id' => null,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => $match->token,
        'raw_text' => buildLeagueRawText([
            'PlayFormatCd' => 'Pmodern',
            'GameStructureCd' => 'Constructed',
            'League Token' => 'idempotent-token',
        ]),
    ]);

    Event::fake([LeagueMatchStarted::class]);

    $listener = new AssignMatchLeague;
    $listener->handle(new MatchJoined($logEvent));

    $firstLeagueId = $match->fresh()->league_id;

    // Second call — listener exits early because league_id is already set
    $listener->handle(new MatchJoined($logEvent));

    expect($match->fresh()->league_id)->toBe($firstLeagueId);
    expect(League::count())->toBe(1);
});
