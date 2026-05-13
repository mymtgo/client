<?php

use App\Actions\Pipeline\Handlers\HandleTournamentRoundInfo;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a tournament_round_info event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'tournament_round_info',
        'tournament_token' => '11111111-1111-1111-1111-111111111111',
        'raw_text' => 'Tournament round info {"round":2}',
        'ingested_at' => now(),
    ]);

    expect(fn () => (new HandleTournamentRoundInfo)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
