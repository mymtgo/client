<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\Sync\NotLinkedException;
use App\Facades\AppSettings;
use App\Models\Account;
use App\Services\Sync\AccountApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Tells the API which MTGO login this client has seen for one local account.
 * Queued rather than called from the log parser: ingestion walks historical
 * files too, and a synchronous request per login row would attest every
 * stale pair in file order. Unique per account so a burst collapses to one.
 *
 * The attempt is retried in place before giving up, because the moment this
 * matters most is the second after linking, when the first sync is hammering
 * the local database and a "database is locked" would otherwise throw the
 * attestation away: the website then shows synced matches under no player.
 * A confirmed login is recorded in settings, so what is still owed survives
 * a restart and every later trigger (a sync run, a username change, leaving
 * offline mode) picks it back up.
 */
class AttestAccount implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /**
     * Unique only until the job starts, not for the whole minute after it.
     * Held to completion, a failed attempt swallowed the dispatch that
     * follows it seconds later: the app starts with a stale token, that
     * attempt 401s, the user links, and the post-link attest never ran.
     * Bursts are still collapsed, and a confirmed login short-circuits in
     * handle() anyway.
     */
    public int $uniqueFor = 60;

    /**
     * Attempts, and the pause before each retry. Short on purpose: this runs
     * on the sync queue connection, which is inline, so the wait is felt by
     * whatever dispatched it.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS_MS = [250, 1000];

    public function __construct(public readonly int $accountId) {}

    public function uniqueId(): string
    {
        return 'attest-account-'.$this->accountId;
    }

    public function handle(AccountApi $api): void
    {
        /*
         * Every exit says why. This job is dispatched from four places and
         * runs inline on the sync queue, so a silent early return is
         * indistinguishable from never having been dispatched, which is
         * exactly the hole that left an account synced but unattested.
         */
        if (AppSettings::isOffline()) {
            Log::info('Attest: skipped, offline mode is on.', ['account_id' => $this->accountId]);

            return;
        }

        if (! app(SyncTokens::class)->linked()) {
            Log::info('Attest: skipped, this device is not linked.', ['account_id' => $this->accountId]);

            return;
        }

        $account = Account::find($this->accountId);

        if ($account === null || $account->login_id === null) {
            Log::info('Attest: skipped, no MTGO login id for this account.', ['account_id' => $this->accountId]);

            return;
        }

        $loginId = (int) $account->login_id;
        $username = (string) $account->username;
        $attested = AppSettings::syncAttested();

        if (($attested[$loginId] ?? null) === $username) {
            return;
        }

        foreach ([null, ...self::RETRY_DELAYS_MS] as $delay) {
            if ($delay !== null) {
                Sleep::for($delay)->milliseconds();
            }

            try {
                if ($api->attest($loginId, $username)) {
                    // Assigned, not array_merge()d: PHP stores a numeric key
                    // as an int, and array_merge renumbers int keys.
                    $attested[$loginId] = $username;
                    AppSettings::setSyncAttested($attested);

                    Log::info('Attest: confirmed the MTGO account.', ['login_id' => $loginId]);
                }

                return;
            } catch (NotLinkedException) {
                // Unlinked between dispatch and run. The next link re-attests.
                return;
            } catch (Throwable $e) {
                $error = $e;
            }
        }

        Log::warning('Attest: could not confirm the MTGO account, leaving it owed.', [
            'login_id' => $loginId,
            'error' => $error->getMessage(),
        ]);
    }
}
