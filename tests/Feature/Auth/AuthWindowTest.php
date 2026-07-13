<?php

use App\Actions\Auth\OpenAuthWindow;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

it('opens the auth window pointed at the local login page', function () {
    $fake = Window::fake()->alwaysReturnWindows([
        new WindowInstance('main'),
    ]);

    app(OpenAuthWindow::class)->run();

    // Closure form on purpose: assertOpened('auth') trips is_callable() on
    // Laravel's global auth() helper inside WindowManagerFake.
    $fake->assertOpened(fn (string $id): bool => $id === 'auth');
});

it('does not open a second auth window when one is already open', function () {
    $fake = Window::fake()->alwaysReturnWindows([
        new WindowInstance('auth'),
    ]);

    app(OpenAuthWindow::class)->run();

    $fake->assertOpenedCount(0);
});
