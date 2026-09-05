<?php

namespace App\Actions\SideboardGuides;

use App\Actions\Reports\GetReportSideboardOracles;
use App\Models\DeckVersion;

class GetVersionZoneQuantities
{
    /**
     * Copies of each card available to bring in (sideboard) and take out
     * (maindeck) in this version, keyed by oracle_id. These are the ceilings a
     * saved plan may not exceed.
     *
     * A card split across zones, or present as two printings, contributes to
     * both totals as appropriate; the signature stores one segment per entry.
     *
     * @return array{in: array<string, int>, out: array<string, int>}
     */
    public static function run(DeckVersion $version): array
    {
        $zones = ['in' => [], 'out' => []];

        foreach ($version->cards as $card) {
            if ($card['oracle_id'] === null) {
                continue;
            }

            $zone = GetReportSideboardOracles::isSideboard($card['sideboard'] ?? false) ? 'in' : 'out';
            $zones[$zone][$card['oracle_id']] = ($zones[$zone][$card['oracle_id']] ?? 0) + (int) $card['quantity'];
        }

        ksort($zones['in']);
        ksort($zones['out']);

        return $zones;
    }
}
