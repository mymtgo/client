<?php

declare(strict_types=1);

namespace App\Actions\Sync\Auth;

use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeAuthorizationCode
{
    public function __construct(private SyncTokens $tokens) {}

    public function run(string $code, string $verifier): void
    {
        $response = Http::mymtgoSync()->asForm()->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('sync_client.oauth.client_id'),
            'redirect_uri' => config('sync_client.oauth.redirect_uri'),
            'code_verifier' => $verifier,
            'code' => $code,
        ]);

        if ($response->failed()) {
            Log::error('Sync device link: token exchange was refused.', ['status' => $response->status()]);

            $response->throw();
        }

        $this->tokens->store(
            (string) $response->json('access_token'),
            (string) $response->json('refresh_token'),
            (int) $response->json('expires_in'),
        );
    }
}
