<?php

namespace App\Actions\Sidecar;

use App\Models\GameEvent;
use App\Models\MtgoMatch;
use App\Sidecar\DeckSnapshot;
use App\Sidecar\LeagueSnapshot;
use App\Sidecar\MatchSnapshot;
use App\Sidecar\SidecarPaths;
use App\Sidecar\SidecarTables;

class ReadMatchSnapshot
{
    public const PHASE_STARTED = 'started';

    public const PHASE_ENDED = 'ended';

    /**
     * The latest verified match_snapshot for a match and phase. Verification
     * is per event (the snapshot's own envelope), not per match: one
     * unverified game tick elsewhere in the match says nothing about this
     * read.
     *
     * isNew is true until ApplySidecarProjection has seen the event: the
     * late corrections act only on a snapshot that has just arrived, so a
     * decision is made once, not re-litigated on every tick.
     */
    public static function run(MtgoMatch $match, string $phase): ?MatchSnapshot
    {
        if (! is_dir(SidecarPaths::directory()) || ! SidecarTables::ready()) {
            return null;
        }

        $event = GameEvent::query()
            ->where('match_mtgo_id', (string) $match->mtgo_id)
            ->where('type', 'match_snapshot')
            ->where('verified', true)
            ->where('data->phase', $phase)
            ->orderByDesc('session_started_at')
            ->orderByDesc('seq')
            ->first();

        if ($event === null) {
            return null;
        }

        return new MatchSnapshot(
            phase: $phase,
            registeredDeck: DeckSnapshot::fromArray($event->data['registered_deck'] ?? null),
            league: LeagueSnapshot::fromArray($event->data['league'] ?? null),
            isNew: $event->processed_at === null,
        );
    }
}
