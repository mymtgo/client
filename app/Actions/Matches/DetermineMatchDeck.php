<?php

namespace App\Actions\Matches;

use App\Models\MtgoMatch;

class DetermineMatchDeck
{
    /**
     * Link the match to the deck version it was played with. The only
     * writer of deck_version_id for this path.
     */
    public static function run(MtgoMatch $match): void
    {
        $result = ResolveMatchDeckFromLog::run($match);

        if ($result['found']) {
            $match->update(['deck_version_id' => $result['version']?->id]);
        }
    }
}
