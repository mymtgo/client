<?php

namespace App\Actions\Debug;

use App\Actions\Matches\PurgeMatchDerivedData;
use App\Models\MtgoMatch;

class TeardownFakeOverlayMatches
{
    /**
     * Remove every fake overlay match and all of its derived data. Keyed by
     * the token prefix so nothing real is ever in scope.
     */
    public static function run(): int
    {
        $matches = MtgoMatch::query()
            ->where('token', 'like', CreateFakeOverlayMatch::TOKEN_PREFIX.'%')
            ->get();

        foreach ($matches as $match) {
            PurgeMatchDerivedData::run($match, includeLogEvents: true);
            $match->forceDelete();
        }

        return $matches->count();
    }
}
