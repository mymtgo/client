<?php

use App\Actions\Pipeline\Handlers\HandleMatchStateChanged;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('does nothing when match_token is missing', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => null,
    ]);

    expect(fn () => (new HandleMatchStateChanged)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);

    expect(MtgoMatch::count())->toBe(0);
});

it('does nothing when match_id cannot be resolved', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'match_token' => 'orphan-token',
        'match_id' => null,
    ]);

    (new HandleMatchStateChanged)->handle($event, new PipelineContext);

    expect(MtgoMatch::count())->toBe(0);
});
