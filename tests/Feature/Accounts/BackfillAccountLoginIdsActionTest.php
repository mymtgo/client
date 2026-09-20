<?php

declare(strict_types=1);

use App\Actions\Accounts\BackfillAccountLoginIds;
use App\Jobs\AttestAccount;
use App\Models\Account;
use App\Models\LogEvent;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function loginRow(string $rawText): LogEvent
{
    return LogEvent::factory()->create([
        'category' => 'Login',
        'context' => 'MtGO Login Success',
        'raw_text' => $rawText,
        'timestamp' => now(),
    ]);
}

it('fills the id and reports how many it filled', function () {
    $account = Account::factory()->create(['username' => 'stubplayer', 'login_id' => null]);
    loginRow('12:23:40 [INF] (Login|MtGO Login Success) Username: stubplayer (555001)');

    expect(BackfillAccountLoginIds::run())->toBe(1)
        ->and($account->fresh()->login_id)->toBe(555001);
});

it('leaves an account whose login lines carry no id', function () {
    $account = Account::factory()->create(['username' => 'stubplayer', 'login_id' => null]);
    loginRow('12:23:40 [INF] (Login|MtGO Login Success) Username: stubplayer');

    expect(BackfillAccountLoginIds::run())->toBe(0)
        ->and($account->fresh()->login_id)->toBeNull();
});

it('queues the attest a filled id makes possible', function () {
    Queue::fake();
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Account::factory()->create(['username' => 'stubplayer', 'login_id' => null]);
    loginRow('12:23:40 [INF] (Login|MtGO Login Success) Username: stubplayer (555001)');

    BackfillAccountLoginIds::run();

    // Account::saved dispatches it, which is what turns a filled id into a
    // claim on the website without the user doing anything.
    Queue::assertPushed(AttestAccount::class, 1);
});
