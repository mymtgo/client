<?php

use App\Actions\Pipeline\Handlers\HandleTournamentRoundResult;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a tournament_round_result event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'tournament_round_result',
        'tournament_token' => '11111111-1111-1111-1111-111111111111',
        'raw_text' => 'Tournament round result {"round":1,"winner":"alice"}',
        'ingested_at' => now(),
    ]);

    expect(fn () => (new HandleTournamentRoundResult)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
