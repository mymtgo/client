<?php

use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Managers\MtgoManager;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::set('debug_mode', true);
});

function reprocessLogEvent(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/reprocess-test.log',
        'byte_offset_start' => random_int(0, 999999),
        'byte_offset_end' => random_int(1000000, 9999999),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => '',
        'raw_text' => '',
        'event_type' => null,
        'logged_at' => now(),
        'ingested_at' => now(),
        'processed_at' => now(),
    ], $overrides));
}

it('purges the match and derived data and resets its log events', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    DB::table('match_archetypes')->insert([
        'archetype_id' => Archetype::factory()->create()->id,
        'mtgo_match_id' => $match->id,
        'player_id' => Player::factory()->create()->id,
        'confidence' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tokenEvent = LogEvent::factory()->create([
        'match_token' => $match->token,
        'match_id' => $match->mtgo_id,
        'event_type' => 'game_management_json',
        'processed_at' => now(),
    ]);

    $gameEvent = LogEvent::factory()->create([
        'game_id' => $game->mtgo_id,
        'event_type' => 'game_state_update',
        'processed_at' => now(),
    ]);

    $this->post("/debug/matches/{$match->id}/reprocess")->assertRedirect();

    expect(MtgoMatch::count())->toBe(0)
        ->and(Game::count())->toBe(0)
        ->and(DB::table('match_archetypes')->count())->toBe(0)
        ->and($tokenEvent->fresh())->not->toBeNull()
        ->and($tokenEvent->fresh()->processed_at)->toBeNull()
        ->and($gameEvent->fresh())->not->toBeNull()
        ->and($gameEvent->fresh()->processed_at)->toBeNull();
});

it('rebuilds the match from its log events even when MTGO paths are invalid', function () {
    // Dev machine scenario: no MTGO install, so RunPipeline::run() would bail
    // on pathsAreValid(). Reprocess must not depend on it.
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(false);
    $mock->shouldReceive('ingestLogs')->never();
    app()->instance('mtgo', $mock);
    Mtgo::setUsername('LocalPlayer');

    $matchId = '88001';
    $token = 'token-reprocess-e2e';

    // Broken projection result: match exists but with wrong state, all events processed.
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => $matchId,
        'token' => $token,
        'state' => MatchState::Started,
        'outcome' => null,
        'ended_at' => null,
    ]);

    $joinRaw = implode("\n", [
        '12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)',
        'Receiver:',
        'PlayFormatCd = Pmodern',
        'GameStructureCd = Constructed',
    ]);

    reprocessLogEvent([
        'match_id' => $matchId,
        'match_token' => $token,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => $joinRaw,
        'username' => 'LocalPlayer',
    ]);

    reprocessLogEvent([
        'match_id' => $matchId,
        'match_token' => $token,
        'event_type' => 'game_management_json',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => $joinRaw,
        'username' => 'LocalPlayer',
    ]);

    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => 'LocalPlayer'],
        ['Id' => 2, 'Name' => 'Opponent'],
    ], 'Cards' => []]);

    reprocessLogEvent([
        'match_id' => $matchId,
        'match_token' => $token,
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 88101,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 88101, Match ID: {$matchId}\n{$stateJson}",
    ]);

    $this->post("/debug/matches/{$match->id}/reprocess")->assertRedirect();

    $rebuilt = MtgoMatch::where('token', $token)->first();

    expect($rebuilt)->not->toBeNull()
        ->and($rebuilt->id)->not->toBe($match->id)
        ->and($rebuilt->state)->toBe(MatchState::InProgress)
        ->and($rebuilt->games()->count())->toBe(1);
});

it('refuses to reprocess a match with no log events', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    $this->from('/debug/matches')
        ->post("/debug/matches/{$match->id}/reprocess")
        ->assertRedirect('/debug/matches')
        ->assertSessionHasErrors('reprocess');

    expect(MtgoMatch::count())->toBe(1)
        ->and(Game::count())->toBe(1);
});
