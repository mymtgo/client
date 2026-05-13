<?php

use App\Actions\Pipeline\Handlers\HandleLeagueJoined;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a league_joined event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'league_joined',
        'match_token' => 'league-token-abc',
        'match_id' => 12345,
    ]);

    expect(fn () => (new HandleLeagueJoined)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
