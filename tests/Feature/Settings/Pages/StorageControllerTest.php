<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
