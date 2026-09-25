<?php

namespace App\Actions\Api;

use App\Facades\AppSettings;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckApiStatus
{
    /**
     * A noauth answer says whether the client is signed in: a signed-in
     * client sends its account token, not the device key, so re-registering
     * the device cannot fix it and the card must point at signing in.
     *
     * @return array{state: 'ok'}|array{state: 'noauth', message: string, signedIn: bool}|array{state: 'unreachable', error: string}|array{state: 'offline'}
     */
    public static function run(): array
    {
        if (AppSettings::isOffline()) {
            return ['state' => 'offline'];
        }

        try {
            $response = Http::mymtgoApi()
                ->timeout(5)
                ->connectTimeout(5)
                ->get('/api/status');
        } catch (Throwable $e) {
            return [
                'state' => 'unreachable',
                'error' => self::formatException($e),
            ];
        }

        if (! $response->successful()) {
            return [
                'state' => 'unreachable',
                'error' => 'HTTP '.$response->status().': '.$response->body(),
            ];
        }

        $payload = $response->json();
        $status = $payload['status'] ?? null;

        if ($status === 'ok') {
            return ['state' => 'ok'];
        }

        if ($status === 'noauth') {
            return [
                'state' => 'noauth',
                'message' => $payload['message'] ?? 'Authentication required.',
                'signedIn' => app(SyncTokens::class)->linked(),
            ];
        }

        return [
            'state' => 'unreachable',
            'error' => 'Unexpected response: '.$response->body(),
        ];
    }

    private static function formatException(Throwable $e): string
    {
        return get_class($e).': '.$e->getMessage();
    }
}
