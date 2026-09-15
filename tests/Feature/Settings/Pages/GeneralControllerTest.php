<?php

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the general settings page with accounts and background props', function () {
    Account::factory()->create(['username' => 'Alice']);

    $this->get(route('settings.general'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/General')
            ->where('currentPage', 'general')
            ->has('accounts', 1, fn (AssertableInertia $account) => $account
                ->where('username', 'Alice')
                ->has('tracked')
                ->has('active')
                ->etc())
            ->has('autostartEnabled')
            ->has('trayAvailable'));
});
