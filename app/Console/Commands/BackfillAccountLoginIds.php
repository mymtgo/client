<?php

namespace App\Console\Commands;

use App\Actions\Accounts\BackfillAccountLoginIds as BackfillAction;
use Illuminate\Console\Command;

class BackfillAccountLoginIds extends Command
{
    protected $signature = 'accounts:backfill-login-ids';

    protected $description = 'Fill accounts.login_id from stored MTGO login rows for accounts that have none.';

    /**
     * The manual entry point for a repair the app also runs by itself, after
     * every log ingestion and hourly from the schedule. A packaged client
     * gives nobody an artisan prompt, so this is for development only.
     */
    public function handle(): int
    {
        $filled = BackfillAction::run();

        $this->info("{$filled} account login ids filled.");

        return self::SUCCESS;
    }
}
