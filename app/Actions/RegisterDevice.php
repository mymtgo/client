<?php

namespace App\Actions;

use App\Actions\Device\ResolveDeviceId;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterDevice
{
    public static function run(): bool
    {
        $deviceId = app(ResolveDeviceId::class)->run();

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
