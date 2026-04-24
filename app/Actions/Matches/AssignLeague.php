<?php

namespace App\Actions\Matches;

use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class AssignLeague
{
    /**
     * Assign a league to the match. Real leagues only; matches without a
     * League Token (or tournament matches) remain unattached.
     */
    public static function run(MtgoMatch $match, array $gameMeta): void
    {
        // Tournament matches are handled separately — no league assignment.
        // Prefer the match column (stamped by AdvanceMatchState) because
        // $gameMeta['Description'] is unreliable for single-line logs where
        // ExtractKeyValueBlock can't split keys correctly.
        $isTournament = $match->tournament_event_id !== null
            || preg_match('/Tournament:\d+\s+Round:\d+/', $gameMeta['Description'] ?? '');

        if ($isTournament) {
            return;
        }

        if (empty($gameMeta['League Token'])) {
            return;
        }

        $league = null;

        // 1. Best path: find by event_id (set by ProcessLeagueEvents)
        //    Active-only: Partial leagues are dropped runs — new matches
        //    should never attach to them.
        if (! empty($gameMeta['EventId'])) {
            $league = League::where('event_id', (int) $gameMeta['EventId'])
                ->where('state', LeagueState::Active)
                ->latest('started_at')
                ->first();
        }

        // 2. Fallback: find by token + deck_version_id
        if (! $league) {
            $leagueKey = [
                'token' => $gameMeta['League Token'],
                'format' => $gameMeta['PlayFormatCd'],
            ];

            if ($match->deck_version_id) {
                $leagueKey['deck_version_id'] = $match->deck_version_id;
            }

            $league = League::where($leagueKey)
                ->where('state', LeagueState::Active)
                ->latest('started_at')
                ->first();
        }

        // 3. Safety net: create reactively
        $isNew = false;
        if (! $league) {
            $league = League::create([
                'token' => $gameMeta['League Token'],
                'format' => $gameMeta['PlayFormatCd'],
                'deck_version_id' => $match->deck_version_id,
                'started_at' => now(),
                'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->toLocal()->format('d-m-Y h:ma')),
            ]);
            $isNew = true;
        }

        if ($isNew) {
            // Mark older active leagues with the same token as partial
            League::where('token', $gameMeta['League Token'])
                ->where('format', $gameMeta['PlayFormatCd'])
                ->where('state', LeagueState::Active)
                ->where('id', '!=', $league->id)
                ->where('started_at', '<=', $league->started_at)
                ->update(['state' => LeagueState::Partial]);
        }

        $match->update(['league_id' => $league->id]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to league #{$league->id}", [
            'league_name' => $league->name,
            'has_league_token' => true,
        ]);
    }
}
