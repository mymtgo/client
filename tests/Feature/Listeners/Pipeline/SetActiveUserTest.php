<?php

use App\Events\UserLoggedIn;
use App\Facades\Mtgo;
use App\Listeners\Pipeline\SetActiveUser;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets the active username from a login event', function () {
    $event = LogEvent::factory()->create([
        'category' => 'Login',
        'context' => 'MtGO Login Success',
        'raw_text' => '10:00:00 [INF] (Login|MtGO Login Success) Username: TestPlayer',
        'username' => 'TestPlayer',
    ]);

    $listener = new SetActiveUser;
    $listener->handle(new UserLoggedIn($event));

    expect(Mtgo::getUsername())->toBe('TestPlayer');
});

it('does nothing when username is null', function () {
    $event = LogEvent::factory()->create([
        'category' => 'Login',
        'context' => 'MtGO Login Success',
        'username' => null,
    ]);

    $listener = new SetActiveUser;
    $listener->handle(new UserLoggedIn($event));

    // Should not throw, just silently skip
});
