<?php

namespace App\Actions;

use App\Models\MtgoMatch;

class CreateMatch
{
    public static function execute(array $incoming)
    {
        $match = MtgoMatch::where('token', $incoming['matchToken'])->withTrashed()->first();

        if ($match) {
            return $match;
        }

        $match = new MtgoMatch;
        $match->status = 'in_progress';
        $match->token = $incoming['matchToken'];
        $match->mtgo_id = $incoming['matchId'];
        $match->format = $incoming['format'];
        $match->match_type = $incoming['type'];
        $match->started_at = now()->parse($incoming['date']);

        $match->save();

        return $match;
    }
}
