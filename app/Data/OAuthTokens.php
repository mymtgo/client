<?php

namespace App\Data;

/**
 * The per-device OAuth token set. Stored encrypted via AppSettings; never
 * log the values.
 */
final class OAuthTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $expiresAt,
    ) {}

    /**
     * @param  array{access_token: string, refresh_token: string, expires_in: int}  $response
     */
    public static function fromTokenResponse(array $response): self
    {
        return new self(
            accessToken: $response['access_token'],
            refreshToken: $response['refresh_token'],
            expiresAt: now()->addSeconds((int) $response['expires_in'])->toIso8601String(),
        );
    }
}
