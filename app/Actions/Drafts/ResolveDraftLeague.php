<?php

namespace App\Actions\Drafts;

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ResolveDraftLeague
{
    /** Prefix of the synthetic token minted when the real one is unknown. */
    public const PLACEHOLDER_PREFIX = 'draft-';

    /**
     * Find or create the League row for one league run identified by MTGO's
     * LeagueID (leagues.event_id) and CourseID (leagues.mtgo_course_id).
     * A new CourseID is a new run, so a new row. Older Active runs of the
     * same league are closed: Complete when they reached their match count,
     * Partial otherwise.
     */
    public static function run(int $leagueId, ?int $courseId, ?Carbon $startedAt = null, ?string $token = null): League
    {
        $league = League::query()
            ->where('event_id', $leagueId)
            ->when($courseId !== null, fn ($q) => $q->where('mtgo_course_id', $courseId))
            ->when($courseId === null, fn ($q) => $q->where('kind', LeagueKind::Draft)->where('state', LeagueState::Active))
            ->latest('started_at')
            ->first();

        if ($league) {
            $league->fill([
                'kind' => LeagueKind::Draft,
                'mtgo_course_id' => $league->mtgo_course_id ?? $courseId,
                'token' => self::realToken($league->token, $token),
                'started_at' => $league->started_at ?? $startedAt,
            ])->save();

            return $league;
        }

        $league = League::create([
            'token' => $token ?? self::PLACEHOLDER_PREFIX."{$leagueId}-".($courseId ?? 'unknown'),
            'event_id' => $leagueId,
            'mtgo_course_id' => $courseId,
            'kind' => LeagueKind::Draft,
            'state' => LeagueState::Active,
            'format' => 'Limited',
            'name' => "Draft League {$leagueId}",
            'started_at' => $startedAt ?? now(),
            'joined_at' => $startedAt,
        ]);

        League::query()
            ->where('event_id', $leagueId)
            ->where('state', LeagueState::Active)
            ->where('id', '!=', $league->id)
            ->get()
            ->each(function (League $older): void {
                $isComplete = $older->matches()->count() >= $older->kind->roundCount();

                $older->update([
                    'state' => $isComplete ? LeagueState::Complete : LeagueState::Partial,
                    'completed_at' => $isComplete ? now() : null,
                ]);
            });

        Log::channel('pipeline')->info("ResolveDraftLeague: created draft league #{$league->id}", [
            'event_id' => $leagueId,
            'course_id' => $courseId,
        ]);

        return $league;
    }

    /**
     * A placeholder token is a stand-in until MTGO tells us the real one.
     * Replace it the moment a panel event does; AssignLeague looks runs up
     * by the real League Token, so a placeholder that never heals splits
     * the run permanently. A real token is never overwritten.
     */
    private static function realToken(?string $current, ?string $token): ?string
    {
        if ($token === null) {
            return $current;
        }

        if ($current === null || str_starts_with($current, self::PLACEHOLDER_PREFIX)) {
            return $token;
        }

        return $current;
    }
}
