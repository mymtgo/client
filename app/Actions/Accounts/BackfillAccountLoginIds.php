<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\LogEvent;

/**
 * Fill accounts.login_id from stored MTGO login rows for accounts that have
 * none, and let Account::saved dispatch the attest that follows.
 *
 * MTGO writes the id on only some login lines ("Username: name (12345)"
 * beside plain "Username: name"), and an account registered from an id-less
 * one, or from the username the manager reads at boot, stays null forever.
 * That account is then invisible to AttestKnownAccounts, which only queues
 * accounts that have an id, so the website ends up holding the user's synced
 * matches under a player row they do not hold.
 *
 * Log rows keep their raw text even when they classify to no event, so the
 * id is usually still there to be read. PruneProcessedLogEvents caps events
 * at 30 days, so this heals a recent install and not an ancient one; the
 * user's next real MTGO login covers the rest.
 */
class BackfillAccountLoginIds
{
    /** How many accounts were given an id by this call. */
    public static function run(): int
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

        return $filled;
    }
}
