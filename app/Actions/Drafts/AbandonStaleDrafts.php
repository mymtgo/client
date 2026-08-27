<?php

namespace App\Actions\Drafts;

use App\Enums\DraftState;
use App\Models\Draft;
use App\Models\DraftPick;
use Carbon\Carbon;

class AbandonStaleDrafts
{
    /**
     * A draft that stopped receiving picks is over, whatever MTGO did or did
     * not log. Silence is not state, but half an hour of it is.
     *
     * Activity is the newest shown_at or picked_at across the draft's picks:
     * a pick can be committed long after its pack was shown, and the pack
     * for the next pick may never arrive, so shown_at alone reads a live
     * draft as stale. With no picks at all, fall back to the draft's own
     * start, and failing that its created_at, so a draft that never got
     * past Connecting is eventually reaped instead of rescanned forever.
     */
    public static function run(int $minutes = 30): void
    {
        $cutoff = now()->subMinutes($minutes);

        Draft::query()
            ->whereIn('state', [DraftState::Connecting, DraftState::Picking])
            ->get()
            ->each(function (Draft $draft) use ($cutoff): void {
                $lastActivity = self::lastActivity($draft);

                if ($lastActivity && $lastActivity->lessThan($cutoff)) {
                    $draft->update(['state' => DraftState::Abandoned]);
                }
            });
    }

    private static function lastActivity(Draft $draft): ?Carbon
    {
        $picks = DraftPick::query()
            ->where('draft_id', $draft->id)
            ->selectRaw('max(shown_at) as last_shown, max(picked_at) as last_picked')
            ->first();

        $latest = max($picks?->last_shown, $picks?->last_picked);

        if ($latest) {
            return Carbon::parse($latest);
        }

        return $draft->started_at ?? $draft->created_at;
    }
}
