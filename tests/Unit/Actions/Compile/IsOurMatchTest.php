<?php

use App\Actions\Compile\IsOurMatch;
use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects a token with only state-change lines (observed match, no game traffic)', function () {
    $instance = LogInstance::factory()->create();
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'event_type' => 'match_state_changed',
        'match_token' => 'observed-tok',
        'raw_text' => 'Match State Changed for observed-tok ...',
    ]);

    expect(app(IsOurMatch::class)->run('observed-tok'))->toBeFalse();
});

it('rejects a token with no events at all', function () {
    expect(app(IsOurMatch::class)->run('never-seen'))->toBeFalse();
});

it('accepts a token with GsMessage game traffic', function () {
    $instance = LogInstance::factory()->create();
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'event_type' => 'game_management_json',
        'match_token' => 'played-tok',
        'game_id' => 954965154,
        'raw_text' => '... GsMessageMessage ... {"MatchToken":"played-tok","MatchID":1,"GameID":954965154}',
    ]);

    expect(app(IsOurMatch::class)->run('played-tok'))->toBeTrue();
});

it('rejects game traffic missing a game id', function () {
    $instance = LogInstance::factory()->create();
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'event_type' => 'game_management_json',
        'match_token' => 'half-tok',
        'game_id' => null,
    ]);

    expect(app(IsOurMatch::class)->run('half-tok'))->toBeFalse();
});
