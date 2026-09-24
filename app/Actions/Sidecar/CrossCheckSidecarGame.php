<?php

namespace App\Actions\Sidecar;

use App\Actions\Util\ExtractJson;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\LogEvent;
use App\Sidecar\CrossCheckResult;
use App\Sidecar\SidecarGameView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CrossCheckSidecarGame
{
    public const VISIBLE_ZONES = ['Battlefield', 'Graveyard', 'Exile'];

    public const MAX_DRIFT_SECONDS = 60;

    /**
     * External verification of the sidecar stream: its keyframes must agree
     * with the Twitch Info snapshots in log_events (game_timelines may now
     * hold sidecar frames, which would compare the sidecar with itself). The
     * in-process probe cannot catch "plausible but wrong" reads; this can.
     */
    public static function run(Game $game, SidecarGameView $view): CrossCheckResult
    {
        $snapshots = LogEvent::query()
            ->where('event_type', 'game_state_update')
            ->where('game_id', $game->mtgo_id)
            ->get(['timestamp', 'raw_text'])
            ->map(fn (LogEvent $e) => [
                'seconds' => self::secondsOfDay((string) $e->timestamp),
                'content' => ExtractJson::run((string) $e->raw_text)->first(),
            ])
            ->filter(fn ($s) => is_array($s['content']))
            ->values();

        if ($snapshots->isEmpty()) {
            return new CrossCheckResult(passed: true, compared: 0, failures: []);
        }

        $tz = AppSettings::systemTimezone() ?: 'UTC';
        $compared = 0;
        $failures = [];

        foreach ($view->keyframes as $keyframe) {
            $cards = $keyframe['cards'] ?? [];
            if ($cards === [] || empty($keyframe['ts'])) {
                continue;
            }

            $kfSeconds = self::secondsOfDay(CarbonImmutable::parse($keyframe['ts'])->setTimezone($tz)->format('H:i:s'));

            $nearest = $snapshots
                ->map(fn ($s) => ['drift' => self::circularDistance($s['seconds'], $kfSeconds), 'content' => $s['content']])
                ->sortBy('drift')
                ->first();

            if ($nearest === null || $nearest['drift'] > self::MAX_DRIFT_SECONDS) {
                continue;
            }

            $compared++;

            $snapshotNames = collect($nearest['content']['Players'] ?? [])->mapWithKeys(fn ($p) => [(int) $p['Id'] => (string) $p['Name']]);

            foreach ($view->playerNames as $slot => $name) {
                $snapshotSlot = $snapshotNames->search($name);
                if ($snapshotSlot === false) {
                    $failures[] = "keyframe turn {$keyframe['turn']}: player {$name} not in snapshot";

                    continue;
                }

                $ours = self::multiset(collect($cards)->where('owner_p', $slot), 'catalog_id', 'zone');
                $theirs = self::multiset(collect($nearest['content']['Cards'] ?? [])->where('Owner', $snapshotSlot), 'CatalogID', 'Zone');

                if ($ours !== $theirs) {
                    $failures[] = "keyframe turn {$keyframe['turn']}: {$name} visible zones differ";
                }
            }
        }

        return new CrossCheckResult(passed: $failures === [], compared: $compared, failures: $failures);
    }

    /** @return array<string, int> "catalog:zone" => count, sorted */
    private static function multiset(Collection $cards, string $catalogKey, string $zoneKey): array
    {
        $counts = [];
        foreach ($cards as $card) {
            $zone = (string) ($card[$zoneKey] ?? '');
            if (! in_array($zone, self::VISIBLE_ZONES, true)) {
                continue;
            }
            $key = ((int) ($card[$catalogKey] ?? 0)).':'.$zone;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private static function secondsOfDay(string $hms): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', substr($hms, 0, 8)) + [0, 0, 0]);

        return $h * 3600 + $m * 60 + $s;
    }

    private static function circularDistance(int $a, int $b): int
    {
        $d = abs($a - $b);

        return min($d, 86400 - $d);
    }
}
