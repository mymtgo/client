<?php

use App\Actions\Pipeline\Handlers\HandleTournamentStateChanged;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a tournament_state_changed event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'tournament_state_changed',
        'tournament_token' => '11111111-1111-1111-1111-111111111111',
        'raw_text' => 'Tournament State Changed for 11111111-1111-1111-1111-111111111111 from Pending to InProgress',
        'ingested_at' => now(),
    ]);

    expect(fn () => (new HandleTournamentStateChanged)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
