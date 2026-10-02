<?php

namespace App\Actions\Leagues;

use App\Exceptions\OfflineModeException;
use App\Models\Archetype;
use App\Support\MtgoFormat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchOpponentScouting
{
    /**
     * What the API knows about an opponent's deck in this format, by source:
     * their latest 5-0 list (`league`), the last deck they played through the
     * tracker (`tracked`), and the deck other tracker users last saw them on
     * (`observed`). Any source can be null; the whole result is null when the
     * API knows nothing or cannot be reached.
     *
     * @return array{league: array{uuid: string, name: string, colors: string|null}|null, tracked: array{uuid: string, name: string, colors: string|null}|null, observed: array{uuid: string, name: string, colors: string|null}|null}|null
     */
    public static function run(string $username, string $rawFormat): ?array
    {
        $format = MtgoFormat::key($rawFormat);

        try {
            $response = Http::mymtgoApi()
                ->post('/api/players', [
                    'username' => $username,
                    'format' => $format,
                ]);
        } catch (OfflineModeException) {
            return null;
        } catch (Throwable $e) {
            Log::warning('Opponent scouting lookup failed', [
                'username' => $username,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $scouting = [
            'league' => self::archetype($response->json('data.league_result.archetype')),
            'tracked' => self::archetype($response->json('data.tracked.archetype')),
            'observed' => self::archetype($response->json('data.observed.archetype')),
        ];

        if ($scouting['league'] === null && $scouting['tracked'] === null && $scouting['observed'] === null) {
            return null;
        }

        return $scouting;
    }

    /**
     * @return array{uuid: string, name: string, colors: string|null}|null
     */
    private static function archetype(mixed $archetype): ?array
    {
        if (! is_array($archetype) || ! isset($archetype['uuid'], $archetype['name'])) {
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
