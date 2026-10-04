<?php

use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Jobs\PublishOverlayStateJob;
use App\Models\Account;
use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('overlay');
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Mtgo::setUsername('StreamPlayer');
    Account::factory()->create(['username' => 'StreamPlayer', 'login_id' => 4242]);
});

it('turning publishing on queues a push', function () {
    Queue::fake();

    $this->post(route('settings.overlay'), ['overlay_publish' => true])->assertRedirect();

    expect(AppSettings::overlayPublish())->toBeTrue();
    Queue::assertPushedOn('overlay', PublishOverlayStateJob::class);
});

it('turning publishing off clears the hosted overlay', function () {
    AppSettings::setOverlayPublish(true);
    AppSettings::setOverlayLastPublishedAt('2026-10-01T10:00:00+00:00');
    $api = Mockery::mock(SyncApi::class);
    $api->shouldReceive('clearOverlay')->once()->with(4242);
    app()->instance(SyncApi::class, $api);

    $this->post(route('settings.overlay'), ['overlay_publish' => false])->assertRedirect();

    expect(AppSettings::overlayPublish())->toBeFalse()
        ->and(AppSettings::overlayLastPublishedAt())->toBeNull();
});

it('turning publishing off survives an unreachable API', function () {
    AppSettings::setOverlayPublish(true);
    $api = Mockery::mock(SyncApi::class);
    $api->shouldReceive('clearOverlay')->andThrow(new RuntimeException('offline'));
    app()->instance(SyncApi::class, $api);

    $this->post(route('settings.overlay'), ['overlay_publish' => false])->assertRedirect();

    expect(AppSettings::overlayPublish())->toBeFalse();
});

it('deleting the background forgets the uploaded copy', function () {
    AppSettings::setOverlayBackgroundRemote(['url' => 'https://cdn.example/bg.png', 'hash' => 'abc']);
    $api = Mockery::mock(SyncApi::class);
    $api->shouldReceive('deleteOverlayBackground')->once();
    app()->instance(SyncApi::class, $api);

    $this->delete(route('settings.overlay.background.delete'))->assertRedirect();

    expect(AppSettings::overlayBackgroundRemote())->toBeNull();
});

it('shares the publish props with the overlays page', function () {
    AppSettings::setOverlayPublish(true);
    AppSettings::setOverlayLastPublishedAt('2026-10-01T10:00:00+00:00');
    AppSettings::setOverlayPublishError('not_claimed');

    $this->get(route('settings.overlays'))->assertInertia(fn ($page) => $page
        ->where('overlayPublish', true)
        ->where('overlayPublicUrl', 'https://mymtgo.com/overlays/StreamPlayer')
        ->where('overlayLastPublishedAt', '2026-10-01T10:00:00+00:00')
        ->where('syncLinked', true)
        ->where('overlayPublishError', 'not_claimed')
        ->has('overlayDeck'));
});

it('shares the OBS browser source size for each card size', function () {
    $this->get(route('settings.overlays'))->assertInertia(fn ($page) => $page
        ->where('overlayObsSizes', ['full' => [332, 132], 'compact' => [272, 90]]));
});
