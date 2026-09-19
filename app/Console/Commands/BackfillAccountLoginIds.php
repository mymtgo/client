<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\LogEvent;
use Illuminate\Console\Command;

class BackfillAccountLoginIds extends Command
{
    protected $signature = 'accounts:backfill-login-ids';

    protected $description = 'Fill accounts.login_id from stored MTGO login rows for accounts that have none.';

    /**
     * Login rows are persisted with their raw text even when they classify
     * to no event, so an account registered before the id was captured can
     * pick it up from history. The newest row for the username wins.
     */
    public function handle(): int
    {
        $filled = 0;

        Account::withTrashed()->whereNull('login_id')->each(function (Account $account) use (&$filled): void {
            $row = LogEvent::query()
                ->where('category', 'Login')
                ->where('context', 'MtGO Login Success')
                ->where('raw_text', 'like', '%Username: '.$account->username.' (%')
                ->orderByDesc('timestamp')
                ->first();

            if ($row === null || ! preg_match('/Username:\s*(\S+)\s*\((\d+)\)/', $row->raw_text, $m) || $m[1] !== $account->username) {
                return;
            }

            $account->update(['login_id' => (int) $m[2]]);
            $filled++;
        });

        $this->info("{$filled} account login ids filled.");

        return self::SUCCESS;
    }
}
