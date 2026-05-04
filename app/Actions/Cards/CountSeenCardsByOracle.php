<?php

namespace App\Actions\Cards;

use App\Models\Game;

class CountSeenCardsByOracle
{
    /**
     * Count distinct card instances visible in the given zones, owned by the given instance id.
     *
     * @param  array<string, string>  $catalogToOracle  CatalogID (string) => oracle_id
     * @param  list<string>  $visibleZones  zones that count as "seen"
     * @return array<string, int> oracle_id => instance count
     */
    public static function run(Game $game, int $instanceId, array $catalogToOracle, array $visibleZones): array
    {
        $seenInstances = [];

        foreach ($game->timeline->sortBy('timestamp') as $snapshot) {
            $cards = $snapshot->content['Cards'] ?? [];

            foreach ($cards as $card) {
                if ((int) ($card['Owner'] ?? -1) !== $instanceId) {
                    continue;
                }

                $zone = $card['Zone'] ?? '';
                $catalogId = (string) ($card['CatalogID'] ?? '');
                $cardInstanceId = (int) ($card['Id'] ?? 0);
                $oracleId = $catalogToOracle[$catalogId] ?? null;

                if (! $oracleId || ! $cardInstanceId) {
                    continue;
                }

                if (in_array($zone, $visibleZones, true)) {
                    $seenInstances[$oracleId][$cardInstanceId] = true;
                }
            }
        }

        return array_map('count', $seenInstances);
    }
}
