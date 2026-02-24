<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Desktop\Facades\Settings;

class RegisterDevice
{
    public static function run(): bool
    {
        $deviceId = Settings::get('device_id');

        if (! $deviceId) {
            $deviceId = (string) Str::uuid();
            Settings::set('device_id', $deviceId);
        }

        try {
            $response = Http::post(config('mymtgo_api.url').'/api/devices/register', [
                'device_id' => $deviceId,
            ]);

            if ($response->successful()) {
                Settings::set('api_key', $response->json('api_key'));

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
}
