<?php

namespace App\Actions;

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RegisterDevice
{
    public static function run(): bool
    {
        $deviceId = AppSettings::deviceId();

        if (! $deviceId) {
            $deviceId = (string) Str::uuid();
            AppSettings::setDeviceId($deviceId);
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->timeout(5)->connectTimeout(5)->post(config('mymtgo_api.url').'/api/devices/register', [
                'device_id' => $deviceId,
            ]);

            if ($response->successful()) {
                AppSettings::setApiKey($response->json('api_key'));
                AppSettings::setApiKeyExpiresAt(now()->addHours(47)->toIso8601String());

                return true;
            }

            Log::error('RegisterDevice: non-2xx response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('RegisterDevice: exception', [
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    public static function retrieveKey(): ?string
    {
        return AppSettings::apiKey();
    }

    /**
     * Re-register when the stored key is missing or expired.
     *
     * Offline mode blocks every job that used to refresh the key as a side
     * effect of a 401 retry, so the allowed card identity requests have to
     * keep it fresh themselves.
     */
    public static function ensureFresh(): void
    {
        $expiresAt = AppSettings::apiKeyExpiresAt();

        if (self::retrieveKey() && $expiresAt && now()->isBefore($expiresAt)) {
            return;
        }

        self::run();
    }
}
