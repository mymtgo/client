<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Actions\Sync\Auth\EnsureAccessToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The account half of the API: what a linked client says about itself.
 * Rides mymtgoSync with an explicit bearer, like SyncApi, so it never
 * touches the device key.
 */
class AccountApi
{
    public function __construct(private EnsureAccessToken $ensureAccessToken) {}

    /**
     * Tell the API which MTGO login this client is signed into. Idempotent
     * on the server. A 409 means another MyMTGO user already owns that MTGO
     * account; that is a support case, not a retry, so it logs and answers
     * false, which keeps the caller from recording it as confirmed. Any
     * other failure throws so the caller retries.
     *
     * @return bool whether this account is now confirmed as ours
     */
    public function attest(int $loginId, string $username): bool
    {
        $response = Http::mymtgoSync()
            ->withToken($this->ensureAccessToken->run())
            ->acceptJson()
            ->post('/api/account/players', [
                'login_id' => $loginId,
                'username' => $username,
            ]);

        if ($response->status() === 409) {
            Log::warning('Attest: MTGO account is owned by another MyMTGO user.', [
                'login_id' => $loginId,
                'username' => $username,
            ]);

            return false;
        }

        $response->throw();

        return true;
    }
}
