<?php

use App\Actions\Pipeline\Handlers\HandleTournamentEnded;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a tournament_ended event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'tournament_ended',
        'tournament_token' => '11111111-1111-1111-1111-111111111111',
        'raw_text' => 'Tournament ended {"winner":"alice"}',
        'ingested_at' => now(),
    ]);

    expect(fn () => (new HandleTournamentEnded)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
