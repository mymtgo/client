<?php

use App\Models\Account;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fills a missing login id from the newest stored login row for that username', function () {
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => null]);

    LogEvent::factory()->create([
        'category' => 'Login',
        'context' => 'MtGO Login Success',
        'raw_text' => '12:23:40 [INF] (Login|MtGO Login Success) Username: anticloser (3022021)',
        'timestamp' => now()->subDay(),
    ]);
    LogEvent::factory()->create([
        'category' => 'Login',
        'context' => 'MtGO Login Success',
        'raw_text' => '12:23:40 [INF] (Login|MtGO Login Success) Username: someoneelse (999)',
        'timestamp' => now(),
    ]);

    $this->artisan('accounts:backfill-login-ids')->assertSuccessful();

    expect($account->fresh()->login_id)->toBe(3022021);
});

it('leaves an account alone when it already has a login id or no login row names it', function () {
    $known = Account::factory()->create(['username' => 'known', 'login_id' => 1]);
    $unknown = Account::factory()->create(['username' => 'unknown', 'login_id' => null]);

    LogEvent::factory()->create([
        'category' => 'Login',
        'context' => 'MtGO Login Success',
        'raw_text' => '12:23:40 [INF] (Login|MtGO Login Success) Username: known (2)',
    ]);

    $this->artisan('accounts:backfill-login-ids')->assertSuccessful();

    expect($known->fresh()->login_id)->toBe(1)
        ->and($unknown->fresh()->login_id)->toBeNull();
});
