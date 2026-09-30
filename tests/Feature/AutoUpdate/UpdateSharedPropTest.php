<?php

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['nativephp.version' => '0.44.0']);
    Cache::forget(ResolveUpdateStatus::DOWNLOADED_KEY);
    Cache::forget(ResolveUpdateStatus::LAST_CHECK_KEY);
});

it('shares the update status on every page', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('update.status', 'ready')
            ->where('update.available', '0.45.0')
            ->where('update.current', '0.44.0')
            ->missing('availableUpdate')
        );
});
