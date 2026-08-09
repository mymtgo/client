<?php

namespace App\Actions\Matches;

use App\Models\MtgoMatch;
use Carbon\Carbon;

class RepairGameTimestampsFromEntries
{
    /**
     * Re-derive each game's started_at and ended_at from a match's decoded log entries.
     *
     * Games are paired with entry groups by position, so a match whose game
     * rows and entry groups disagree in count will mis-map. Callers that
     * cannot vouch for the pairing should compare the counts first.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @return int the number of games updated
     */
    public static function run(MtgoMatch $match, array $entries): int
    {
        $gameGroups = ExtractGameResults::splitIntoGames($entries);
        $games = $match->games()->orderBy('started_at')->orderBy('id')->get();

        $updated = 0;

        foreach ($games as $index => $game) {
            $gameEntries = $gameGroups[$index] ?? [];

            if (empty($gameEntries)) {
                continue;
            }

            $game->update([
                'started_at' => Carbon::parse($gameEntries[0]['timestamp']),
                'ended_at' => Carbon::parse(end($gameEntries)['timestamp']),
            ]);

            $updated++;
        }

        return $updated;
    }
}
