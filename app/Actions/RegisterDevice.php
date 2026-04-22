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
            $response = Http::post(config('mymtgo_api.url').'/api/devices/register', [
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
}
