<?php

use App\Facades\AppSettings;
use App\Jobs\AttestAccount;
use App\Jobs\DownloadArchetypes;
use App\Models\Account;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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

it('re-attests known accounts when a linked client rejoins', function () {
    AppSettings::setOffline(true);
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Queue::fake();
    $keyed = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);
    Account::factory()->create(['username' => 'unknown', 'login_id' => null]);

    $this->patch(route('settings.offline-mode'), ['enabled' => false])
        ->assertRedirect();

    Queue::assertPushed(AttestAccount::class, fn (AttestAccount $job) => $job->accountId === $keyed->id);
    Queue::assertPushed(AttestAccount::class, 1);
});

it('does not attest when rejoining unlinked', function () {
    AppSettings::setOffline(true);
    app(SyncTokens::class)->clear();
    Queue::fake();
    Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    $this->patch(route('settings.offline-mode'), ['enabled' => false]);

    Queue::assertNotPushed(AttestAccount::class);
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
