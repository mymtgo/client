<?php

namespace App\Actions\Cards;

use App\Models\Game;

class CountZonesByOracle
{
    /**
     * Zones counted separately, in the order a card usually meets them.
     *
     * Stack is deliberately absent. A card on the stack is one the player is
     * casting, which `cast` already records, and counting it as a zone would
     * double count every spell.
     *
     * @var list<string>
     */
    public const ZONES = ['hand', 'graveyard', 'exile', 'battlefield'];

    /**
     * Distinct card instances observed per zone, plus the copies that went
     * from hand to graveyard.
     *
     * CountSeenCardsByOracle answers "was this card anywhere visible", which
     * is the union of these. That union cannot tell a card drawn and stranded
     * from one discarded to a looting effect, and for a reanimator deck those
     * are opposite outcomes. Atraxa registers all four copies as seen in a
     * quarter of her games because they were binned, not drawn.
     *
     * Discards are counted by instance: a copy seen in hand in one snapshot
     * and in the graveyard in a later one left the hand for the yard. A cast
     * spell passes through the graveyard the same way, so the caller drops
     * discards for a card it also cast rather than this method guessing.
     *
     * @param  array<string, string>  $catalogToOracle  CatalogID (string) => oracle_id
     * @return array{zones: array<string, array<string, int>>, discarded: array<string, int>}
     */
    public static function run(Game $game, int $instanceId, array $catalogToOracle): array
    {
        /** @var array<string, array<string, array<int, true>>> $instances */
        $instances = [];

        /** @var array<string, array<int, true>> $discarded */
        $discarded = [];

        /** @var array<int, string> $lastZone */
        $lastZone = [];

        /** @var array<int, string> $oracleOfInstance */
        $oracleOfInstance = [];

        foreach ($game->timeline->sortBy('timestamp') as $snapshot) {
            foreach ($snapshot->content['Cards'] ?? [] as $card) {
                if ((int) ($card['Owner'] ?? -1) !== $instanceId) {
                    continue;
                }

                $oracleId = $catalogToOracle[(string) ($card['CatalogID'] ?? '')] ?? null;
                $cardInstanceId = (int) ($card['Id'] ?? 0);

                if (! $oracleId || ! $cardInstanceId) {
                    continue;
                }

                $zone = strtolower((string) ($card['Zone'] ?? ''));

                if (! in_array($zone, self::ZONES, true)) {
                    continue;
                }

                $instances[$zone][$oracleId][$cardInstanceId] = true;
                $oracleOfInstance[$cardInstanceId] = $oracleId;

                if ($zone === 'graveyard' && ($lastZone[$cardInstanceId] ?? null) === 'hand') {
                    $discarded[$oracleId][$cardInstanceId] = true;
                }

                $lastZone[$cardInstanceId] = $zone;
            }
        }

        $zones = [];
        foreach (self::ZONES as $zone) {
            $zones[$zone] = array_map('count', $instances[$zone] ?? []);
        }

        return [
            'zones' => $zones,
            'discarded' => array_map('count', $discarded),
        ];
    }
}
