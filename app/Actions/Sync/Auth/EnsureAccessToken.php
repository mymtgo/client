<?php

declare(strict_types=1);

namespace App\Actions\Sync\Auth;

use App\Exceptions\Sync\NotLinkedException;
use App\Services\Sync\SyncTokens;
use Carbon\CarbonImmutable;

/**
 * The single entry point callers should use to get a usable access token:
 * refreshes ahead of expiry so a request never races an about-to-expire
 * token, and turns "nothing usable is stored" into one exception type.
 */
class EnsureAccessToken
{
    public function __construct(
        private SyncTokens $tokens,
        private RefreshAccessToken $refresh,
    ) {}

    public function run(): string
    {
        if (! $this->tokens->linked()) {
            throw new NotLinkedException;
        }

        $expiresAt = $this->tokens->expiresAt();

        if ($expiresAt === null || $expiresAt->lessThan(CarbonImmutable::now()->addHours(24))) {
            $this->refresh->run();
        }

        $accessToken = $this->tokens->accessToken();

        if ($accessToken === null) {
            throw new NotLinkedException;
        }

        return $accessToken;
    }
}
