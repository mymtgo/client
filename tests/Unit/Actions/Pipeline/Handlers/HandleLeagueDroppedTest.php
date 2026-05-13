<?php

use App\Actions\Pipeline\Handlers\HandleLeagueDropped;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a league_dropped event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'league_dropped',
    ]);

    expect(fn () => (new HandleLeagueDropped)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
