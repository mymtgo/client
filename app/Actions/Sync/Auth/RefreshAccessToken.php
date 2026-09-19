<?php

declare(strict_types=1);

namespace App\Actions\Sync\Auth;

use App\Exceptions\Sync\NotLinkedException;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefreshAccessToken
{
    public function __construct(private SyncTokens $tokens) {}

    public function run(): void
    {
        $refreshToken = $this->tokens->refreshToken();

        if ($refreshToken === null) {
            throw new NotLinkedException;
        }

        $response = Http::mymtgoSync()->asForm()->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('sync_client.oauth.client_id'),
            'refresh_token' => $refreshToken,
        ]);

        // Passport's specific signal for "this refresh token is dead and will
        // never work again" is a 400/401 carrying error=invalid_grant. That
        // is a permanent state, not a transient failure, so the local
        // credentials are cleared rather than retried. Any other 4xx/5xx is
        // treated as an ordinary failure (logged and rethrown) so a
        // malformed request or an unrelated server error can't silently
        // unlink an otherwise-valid device.
        if (in_array($response->status(), [400, 401], true) && $response->json('error') === 'invalid_grant') {
            Log::warning('Sync device link: refresh token was revoked; clearing local credentials.', [
                'status' => $response->status(),
            ]);

            $this->tokens->clear();

            throw new NotLinkedException;
        }

        if ($response->failed()) {
            Log::error('Sync device link: token refresh failed.', ['status' => $response->status()]);

            $response->throw();
        }

        $this->tokens->store(
            (string) $response->json('access_token'),
            (string) $response->json('refresh_token'),
            (int) $response->json('expires_in'),
        );
    }
}
