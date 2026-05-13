<?php

use App\Actions\Pipeline\Handlers\HandleDeckUsed;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does not throw on a deck_used event', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => 12345,
    ]);

    expect(fn () => (new HandleDeckUsed)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
