<?php

use App\Facades\AppSettings;
use App\Jobs\MoveCardImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('cards');
});

it('renders the storage settings page with path, watcher and image props', function () {
    Storage::disk('cards')->put('a.jpg', str_repeat('x', 2048));

    $this->get(route('settings.storage'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Storage')
            ->where('currentPage', 'storage')
            ->has('logPath')
            ->has('dataPath')
            ->has('logPathStatus.valid')
            ->has('dataPathStatus.valid')
            ->has('watcherActive')
            ->has('localImages')
            ->where('localImagesSize', '2 KB'));
});

it('shares the default card image folder', function () {
    $this->get(route('settings.storage'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cardImages.path', config('filesystems.disks.cards.default_root'))
            ->where('cardImages.isDefault', true)
            ->where('cardImages.missing', false)
            ->where('cardImages.moving', false)
            ->where('cardImages.error', null));
});

it('flags a custom card image folder that is no longer reachable', function () {
    AppSettings::setCardImagesPath('/nonexistent/drive/'.MoveCardImages::SUBFOLDER);

    $this->get(route('settings.storage'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cardImages.path', '/nonexistent/drive/'.MoveCardImages::SUBFOLDER)
            ->where('cardImages.isDefault', false)
            ->where('cardImages.missing', true));
});

it('shares an in-flight move and the last move error', function () {
    Cache::forever(MoveCardImages::MOVING_KEY, true);
    Cache::forever(MoveCardImages::ERROR_KEY, 'Moving card images failed: disk full');

    $this->get(route('settings.storage'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cardImages.moving', true)
            ->where('cardImages.error', 'Moving card images failed: disk full'));
});
