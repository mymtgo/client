<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Facades\AppSettings;
use App\Jobs\AttestAccount;
use App\Models\Account;

/**
 * Queue an attest for every local account whose MTGO login id is known.
 * Runs right after the client links, when offline mode is switched off
 * (AttestAccount skips silently while offline, so anything learned in that
 * window is owed), and at the end of every sync run, which is what makes a
 * lost attempt heal rather than stranding the website with synced matches
 * and no linked player.
 */
class AttestKnownAccounts
{
    /**
     * @return array{confirmed: int, owed: int} accounts confirmed by this
     *                                          call, and accounts still owing an attestation afterwards
     */
    public static function run(): array
    {
        $accounts = Account::query()
            ->whereNotNull('login_id')
            ->get(['id', 'login_id']);

        $before = AppSettings::syncAttested();

        $accounts->each(fn (Account $account) => AttestAccount::dispatch($account->id));

        $after = AppSettings::syncAttested();

        $confirmed = 0;
        $owed = 0;

        foreach ($accounts as $account) {
            $loginId = (int) $account->login_id;

            if (! array_key_exists($loginId, $after)) {
                $owed++;

                continue;
            }

            if (($before[$loginId] ?? null) !== $after[$loginId]) {
                $confirmed++;
            }
        }

        return ['confirmed' => $confirmed, 'owed' => $owed];
    }
}
