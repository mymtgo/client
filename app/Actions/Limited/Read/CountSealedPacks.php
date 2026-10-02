<?php

namespace App\Actions\Limited\Read;

use App\Enums\LeagueKind;
use App\Models\League;

class CountSealedPacks
{
    /**
     * How many boosters a sealed pool was opened from: the count in the
     * format code (S6FRA from a match, FRAx6 from the league panel), plus
     * one once the booster added mid-run shows up in a registered deck.
     * Null for drafts and for a format code that carries no count.
     */
    public static function run(League $league): ?int
    {
        if ($league->kind !== LeagueKind::Sealed) {
            return null;
        }

        $format = (string) $league->format;

        if (! preg_match('/^S(?<packs>\d+)[A-Z]/', $format, $m) && ! preg_match('/x(?<packs>\d+)$/', $format, $m)) {
            return null;
        }

        return (int) $m['packs'] + (ReadSealedPool::run($league)['added'] === [] ? 0 : 1);
    }
}
