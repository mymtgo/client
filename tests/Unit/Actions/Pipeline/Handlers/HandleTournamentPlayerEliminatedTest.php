<?php

use App\Actions\Pipeline\Handlers\HandleTournamentPlayerEliminated;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a tournament_player_eliminated event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'tournament_player_eliminated',
        'tournament_token' => '11111111-1111-1111-1111-111111111111',
        'raw_text' => 'Tournament player eliminated {"player":"alice"}',
        'ingested_at' => now(),
    ]);

    expect(fn () => (new HandleTournamentPlayerEliminated)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
