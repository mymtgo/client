<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Facades\AppSettings;
use Carbon\CarbonImmutable;

/**
 * Reads and writes the device's OAuth credentials through the AppSettings
 * store. Access and refresh tokens are held encrypted (AppSettings' Crypt
 * pattern); the expiry is stored as a plain ISO-8601 string since it carries
 * no secret.
 */
class SyncTokens
{
    public function accessToken(): ?string
    {
        return AppSettings::syncAccessToken();
    }

    public function refreshToken(): ?string
    {
        return AppSettings::syncRefreshToken();
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = AppSettings::syncTokenExpiresAt();

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    public function store(string $access, string $refresh, int $expiresIn): void
    {
        AppSettings::setSyncAccessToken($access);
        AppSettings::setSyncRefreshToken($refresh);
        AppSettings::setSyncTokenExpiresAt(CarbonImmutable::now()->addSeconds($expiresIn)->toIso8601String());
    }

    public function clear(): void
    {
        AppSettings::setSyncAccessToken(null);
        AppSettings::setSyncRefreshToken(null);
        AppSettings::setSyncTokenExpiresAt(null);
    }

    public function linked(): bool
    {
        return $this->accessToken() !== null && $this->refreshToken() !== null;
    }
}
