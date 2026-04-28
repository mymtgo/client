<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the setup page with current paths and statuses', function () {
    AppSettings::setLogPath('/tmp/logs');
    AppSettings::setLogDataPath('/tmp/data');

    $this->get('/setup')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/Index')
            ->has('logPath')
            ->has('dataPath')
            ->has('logPathStatus.valid')
            ->has('dataPathStatus.valid')
            ->has('archetypeCount')
            ->has('deckCount')
            ->has('setupSkippedArchetypes')
            ->has('setupSkippedDecks')
        );
});
