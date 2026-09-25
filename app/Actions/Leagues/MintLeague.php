<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;
use Carbon\CarbonInterface;

class MintLeague
{
    /**
     * Create a league run for the match. Leagues are only ever minted when
     * a match arrives carrying a League Token but no matching league exists,
     * so deck_version_id is known at creation time. Shared by the log path
     * and the sidecar decision applier.
     */
    public static function run(MtgoMatch $match, string $token, ?int $eventId, ?string $format, ?string $structure, ?CarbonInterface $joinedAt): League
    {
        $league = League::create([
            'token' => $token,
            'event_id' => $eventId,
            'format' => $format,
            'deck_version_id' => $match->deck_version_id,
            'started_at' => $match->started_at ?? now(),
            'joined_at' => $joinedAt,
            'name' => trim(($structure ?? '').' League '.now()->toLocal()->format('d-m-Y h:ma')),
            'kind' => MtgoMatch::isLimitedFormatCode($format) ? LeagueKind::Draft : LeagueKind::Constructed,
        ]);

        // Mark older active leagues with the same token as partial. Format is
        // intentionally excluded: see FindLogLeagueCandidate step 2.
        League::where('token', $token)
            ->where('state', LeagueState::Active)
            ->where('id', '!=', $league->id)
            ->where('started_at', '<=', $league->started_at)
            ->update(['state' => LeagueState::Partial]);

        return $league;
    }
}
