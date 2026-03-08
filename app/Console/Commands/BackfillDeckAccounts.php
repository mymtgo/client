<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Deck;
use App\Models\LogCursor;
use Illuminate\Console\Command;

class BackfillDeckAccounts extends Command
{
    protected $signature = 'decks:backfill-accounts';

    protected $description = 'Assign existing decks to the current MTGO account and register it';

    public function handle(): int
    {
        $username = LogCursor::first()?->local_username;

        if (! $username) {
            $this->warn('No username found in LogCursor. Run the app and log into MTGO first.');

            return self::FAILURE;
        }

        $account = Account::registerAndActivate($username);
        $this->info("Registered account: {$account->username}");

        $updated = Deck::whereNull('account_id')->update(['account_id' => $account->id]);
        $this->info("Backfilled {$updated} deck(s) with account: {$account->username}");

        return self::SUCCESS;
    }
}
