<?php

use App\Events\LogInstanceSealed;
use App\Listeners\ResolveMatchesOnInstanceSealed;
use Illuminate\Support\Facades\Event;

it('is registered as a listener for LogInstanceSealed', function () {
    Event::fake();

    LogInstanceSealed::dispatch(1, 'truncated');

    Event::assertListening(LogInstanceSealed::class, ResolveMatchesOnInstanceSealed::class);
});

it('invokes ReconcileStuckMatches when handled', function () {
    $called = false;
    $listener = new ResolveMatchesOnInstanceSealed(function () use (&$called) {
        $called = true;
    });

    $listener->handle(new LogInstanceSealed(1, 'truncated'));

    expect($called)->toBeTrue();
});
