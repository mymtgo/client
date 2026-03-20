<?php

use App\Listeners\Pipeline\HandleFileChange;

it('is instantiable', function () {
    $listener = new HandleFileChange;

    expect($listener)->toBeInstanceOf(HandleFileChange::class);
});

it('has a handle method that accepts a MessageReceived event', function () {
    expect(method_exists(HandleFileChange::class, 'handle'))->toBeTrue();

    $reflection = new ReflectionMethod(HandleFileChange::class, 'handle');
    expect($reflection->getParameters())->toHaveCount(1);
    expect($reflection->getParameters()[0]->getName())->toBe('event');
});
