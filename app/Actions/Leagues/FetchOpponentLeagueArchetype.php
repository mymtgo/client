<?php

namespace App\Actions\Leagues;

use App\Exceptions\OfflineModeException;
use App\Models\Archetype;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchOpponentLeagueArchetype
{
    /**
     * Fetch the latest 5-0 league archetype for an opponent from the API.
     * The API only stores 5-0 finishes, so any successful response is a 5-0.
     *
     * @return array{uuid: string, name: string, colors: string|null}|null
     */
    public static function run(string $username, string $rawFormat): ?array
    {
        $format = strtolower(MtgoMatch::displayFormat($rawFormat));

        try {
            $response = Http::mymtgoApi()
                ->post('/api/players', [
                    'username' => $username,
                    'format' => $format,
                ]);
        } catch (OfflineModeException) {
            return null;
        } catch (Throwable $e) {
            Log::warning('Opponent league lookup failed', [
                'username' => $username,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $archetype = $response->json('data.league_result.archetype');

        if (! $archetype || ! isset($archetype['uuid'], $archetype['name'])) {
            return null;
        }

        $colors = Archetype::query()
            ->where('uuid', $archetype['uuid'])
            ->value('color_identity');

        return [
            'uuid' => $archetype['uuid'],
            'name' => $archetype['name'],
            'colors' => $colors,
        ];
    }
}
