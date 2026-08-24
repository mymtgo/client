<?php

namespace App\Actions\Api;

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckApiStatus
{
    /**
     * @return array{state: 'ok'}|array{state: 'noauth', message: string}|array{state: 'unreachable', error: string}|array{state: 'offline'}
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
