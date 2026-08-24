<?php

use App\Facades\AppSettings;
use App\Jobs\DownloadArchetypes;
use Illuminate\Support\Facades\Queue;

it('enables offline mode without contacting the api', function () {
    AppSettings::setOffline(false);
    Queue::fake();

    $this->patch(route('settings.offline-mode'), ['enabled' => true])
        ->assertRedirect();

    expect(AppSettings::isOffline())->toBeTrue();

    Queue::assertNotPushed(DownloadArchetypes::class);
});

it('resyncs the archetype catalog when rejoining', function () {
    AppSettings::setOffline(true);
    Queue::fake();

    $this->patch(route('settings.offline-mode'), ['enabled' => false])
        ->assertRedirect();

    expect(AppSettings::isOffline())->toBeFalse();

    Queue::assertPushed(DownloadArchetypes::class);
});

it('does not resync when already online', function () {
    AppSettings::setOffline(false);
    Queue::fake();

    $this->patch(route('settings.offline-mode'), ['enabled' => false]);

    Queue::assertNotPushed(DownloadArchetypes::class);
});

it('rejects requests without the enabled flag and leaves the setting unchanged', function () {
    AppSettings::setOffline(true);
    Queue::fake();

    $this->patch(route('settings.offline-mode'), [])
        ->assertSessionHasErrors('enabled');

    expect(AppSettings::isOffline())->toBeTrue();

    Queue::assertNotPushed(DownloadArchetypes::class);
});
