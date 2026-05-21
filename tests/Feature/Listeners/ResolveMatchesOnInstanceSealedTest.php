<?php

use App\Actions\Matches\ReconcileStuckMatches;
use App\Events\LogInstanceSealed;
use App\Listeners\ResolveMatchesOnInstanceSealed;
use Illuminate\Support\Facades\Event;

it('is registered as a listener for LogInstanceSealed', function () {
    Event::fake();

    LogInstanceSealed::dispatch(1, 'truncated');

    Event::assertListening(LogInstanceSealed::class, ResolveMatchesOnInstanceSealed::class);
});

it('invokes ReconcileStuckMatches when handled', function () {
    Mockery::mock('alias:'.ReconcileStuckMatches::class)
        ->shouldReceive('run')
        ->once();

    (new ResolveMatchesOnInstanceSealed)->handle(new LogInstanceSealed(1, 'truncated'));
});
