<?php

use App\Models\Account;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runSyncedDeckAccountsMigration(): void
{
    $migration = require database_path('migrations/2026_09_20_085128_backfill_synced_deck_accounts.php');
    $migration->up();
}

it('files decks left unattached by an earlier sync under the account', function () {
    $account = Account::factory()->create(['active' => true]);
    $orphan = Deck::factory()->create(['account_id' => null]);
    $trashed = Deck::factory()->create(['account_id' => null]);
    $trashed->delete();

    runSyncedDeckAccountsMigration();

    expect($orphan->fresh()->account_id)->toBe($account->id)
        ->and(Deck::withTrashed()->find($trashed->id)->account_id)->toBe($account->id);
});

it('leaves orphans alone on a device with two accounts', function () {
    Account::factory()->create(['active' => true]);
    Account::factory()->create(['active' => false]);
    $orphan = Deck::factory()->create(['account_id' => null]);

    runSyncedDeckAccountsMigration();

    expect($orphan->fresh()->account_id)->toBeNull();
});

it('does not move a deck that already has an account', function () {
    $account = Account::factory()->create(['active' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);

    runSyncedDeckAccountsMigration();
    runSyncedDeckAccountsMigration();

    expect($deck->fresh()->account_id)->toBe($account->id);
});
