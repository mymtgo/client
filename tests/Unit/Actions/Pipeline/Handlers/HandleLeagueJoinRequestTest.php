<?php

use App\Actions\Pipeline\Handlers\HandleLeagueJoinRequest;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a league_join_request event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'league_join_request',
    ]);

    expect(fn () => (new HandleLeagueJoinRequest)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
