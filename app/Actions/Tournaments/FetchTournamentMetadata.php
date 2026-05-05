<?php

namespace App\Actions\Tournaments;

use App\Actions\RegisterDevice;
use App\Facades\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchTournamentMetadata
{
    /**
     * Fetch tournament name/format/started_at from the API by mtgo_event_id.
     * Returns null on any failure (404, network, auth) — caller falls back
     * to a synthesized name and the row can be refreshed later.
     *
     * @return array{name: string, format: ?string, started_at: ?Carbon}|null
     */
    public static function run(int $mtgoEventId): ?array
    {
        try {
            $response = Http::withHeaders([
                'X-Device-Id' => AppSettings::deviceId(),
                'X-Api-Key' => RegisterDevice::retrieveKey(),
                'Accept' => 'application/json',
            ])
                ->timeout(5)
                ->get(config('mymtgo_api.url')."/api/tournaments/{$mtgoEventId}");

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            return [
                'name' => (string) ($body['name'] ?? ''),
                'format' => $body['format'] ?? null,
                'started_at' => isset($body['started_at'])
                    ? Carbon::parse($body['started_at'])
                    : null,
            ];
        } catch (Throwable $e) {
            Log::warning('FetchTournamentMetadata: lookup failed', [
                'mtgo_event_id' => $mtgoEventId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
