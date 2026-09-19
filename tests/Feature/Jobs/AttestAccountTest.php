<?php

use App\Facades\AppSettings;
use App\Jobs\AttestAccount;
use App\Models\Account;
use App\Services\Sync\AccountApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

// The Feature suite's global beforeEach registers a blanket Http::fake();
// reset it so each test's own fake is the only one in play.
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
});

it('posts the login id and username with the bearer', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Http::fake(['*/api/account/players' => Http::response(['id' => 1, 'username' => 'anticloser', 'login_id' => 3022021, 'public' => false])]);
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    (new AttestAccount($account->id))->handle(app(AccountApi::class));

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/account/players')
        && $request->hasHeader('Authorization', 'Bearer access-token')
        && $request['login_id'] === 3022021
        && $request['username'] === 'anticloser');
});

it('logs and stops on 409 rather than retrying', function () {
    Log::spy();
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Http::fake(['*/api/account/players' => Http::response(['reason' => 'owned_by_other'], 409)]);
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    (new AttestAccount($account->id))->handle(app(AccountApi::class));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'owned'))->once();
});

it('does nothing for an account without a login id or when not linked', function () {
    Http::fake();
    $unkeyed = Account::factory()->create(['username' => 'a', 'login_id' => null]);
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    (new AttestAccount($unkeyed->id))->handle(app(AccountApi::class));

    app(SyncTokens::class)->clear();
    $keyed = Account::factory()->create(['username' => 'b', 'login_id' => 5]);
    (new AttestAccount($keyed->id))->handle(app(AccountApi::class));

    Http::assertNothingSent();
});

it('dispatches when a linked client learns or changes an account login id', function () {
    Queue::fake();
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => null]);
    Queue::assertNotPushed(AttestAccount::class);

    $account->update(['login_id' => 3022021]);
    Queue::assertPushed(AttestAccount::class, 1);

    $account->update(['tracked' => false]);
    Queue::assertPushed(AttestAccount::class, 1);
});

it('dispatches nothing when the client is not linked', function () {
    Queue::fake();
    app(SyncTokens::class)->clear();

    Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    Queue::assertNotPushed(AttestAccount::class);
});

it('keeps trying when the first attempts fail, then records the confirmation', function () {
    Sleep::fake();
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);

    $attempt = 0;
    Http::fake(['*/api/account/players' => function () use (&$attempt) {
        $attempt++;

        return $attempt < 3
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response(['id' => 1, 'username' => 'anticloser', 'login_id' => 3022021, 'public' => false]);
    }]);

    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    (new AttestAccount($account->id))->handle(app(AccountApi::class));

    expect($attempt)->toBe(3)
        ->and(AppSettings::syncAttested())->toBe(['3022021' => 'anticloser']);
});

it('leaves the account owed when every attempt fails', function () {
    Sleep::fake();
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Http::fake(['*/api/account/players' => Http::response(['message' => 'Server Error'], 500)]);
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    (new AttestAccount($account->id))->handle(app(AccountApi::class));

    expect(AppSettings::syncAttested())->toBe([]);
});

it('does not post again once the same login and username are confirmed', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    AppSettings::setSyncAttested(['3022021' => 'anticloser']);
    Http::fake();
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    (new AttestAccount($account->id))->handle(app(AccountApi::class));

    Http::assertNothingSent();
});

it('posts again when the username behind a confirmed login changes', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    AppSettings::setSyncAttested(['3022021' => 'oldname']);
    Http::fake(['*/api/account/players' => Http::response(['id' => 1, 'username' => 'anticloser', 'login_id' => 3022021, 'public' => false])]);
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    (new AttestAccount($account->id))->handle(app(AccountApi::class));

    Http::assertSent(fn ($request) => $request['username'] === 'anticloser');
    expect(AppSettings::syncAttested())->toBe(['3022021' => 'anticloser']);
});

it('records nothing when the MTGO account belongs to another user', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Http::fake(['*/api/account/players' => Http::response(['reason' => 'owned_by_other'], 409)]);
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    (new AttestAccount($account->id))->handle(app(AccountApi::class));

    expect(AppSettings::syncAttested())->toBe([]);
});

it('still attests after an earlier attempt failed inside the unique window', function () {
    Sleep::fake();
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    $account = Account::factory()->create(['username' => 'anticloser', 'login_id' => 3022021]);

    // The real sequence: the app starts holding a stale token, that attempt
    // 401s, then the user links seconds later. The second dispatch must not
    // be swallowed as a duplicate of the first.
    Http::fake(['*/api/account/players' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    AttestAccount::dispatch($account->id);

    // Http::fake() merges onto the existing stubs and the first match wins,
    // so the 401 above has to be cleared before the success stub is useful.
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    Http::fake(['*/api/account/players' => Http::response(['id' => 1, 'username' => 'anticloser', 'login_id' => 3022021, 'public' => false])]);
    AttestAccount::dispatch($account->id);

    expect(AppSettings::syncAttested())->toBe([3022021 => 'anticloser']);
});
