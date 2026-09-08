<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueKind;
use App\Enums\MatchState;
use App\Models\League;

class GetManualMatchLeagueOptions
{
    /**
     * Leagues a manual match can be filed under: constructed runs that still
     * have a round to fill. Auto-tracked leagues are included on purpose. A
     * manual match is the fix for a league match the app never saw.
     *
     * @return array<int, array{id: int, name: string, deckId: int|null, matchCount: int, roundCount: int}>
     */
    public static function run(?int $deckId = null): array
    {
        return League::query()
            ->where('kind', LeagueKind::Constructed)
            ->when($deckId !== null, fn ($q) => $q->where(function ($w) use ($deckId) {
                $w->whereNull('deck_version_id')
                    ->orWhereHas('deckVersion', fn ($dq) => $dq->where('deck_id', $deckId));
            }))
            // Room is checked in SQL, before the limit. Most finished runs are
            // 5/5, so filtering after a newest-50 cut would return nothing.
            ->has('matches', '<', LeagueKind::Constructed->roundCount(), 'and', fn ($q) => $q->where('state', MatchState::Complete))
            ->withCount(['matches as complete_matches_count' => fn ($q) => $q->where('state', MatchState::Complete)])
            ->with('deckVersion:id,deck_id')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (League $league) => [
                'id' => $league->id,
                'name' => $league->name,
                'deckId' => $league->deckVersion?->deck_id,
                'matchCount' => (int) $league->complete_matches_count,
                'roundCount' => $league->kind->roundCount(),
            ])
            ->values()
            ->all();
    }
}
