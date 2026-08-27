<?php

namespace App\Actions\Drafts;

use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\League;

class LinkUnlinkedDrafts
{
    /**
     * A draft needs enough picks logged to be positive evidence for a link,
     * not a handful of early picks that could belong to any pod.
     */
    private const MIN_PICKS_FOR_LINKAGE = 20;

    /** Attach a league-less draft to the draft league identified by MTGO LeagueID. */
    public static function linkByLeagueId(Draft $draft, int $leagueId): void
    {
        if ($draft->league_id) {
            return;
        }

        $league = ResolveDraftLeague::run($leagueId, null, $draft->started_at);
        $draft->update(['league_id' => $league->id]);
    }

    /**
     * Drafts first seen mid-way have no LeagueID. Once a match has been
     * played with a deck built from the pool, the league that owns that
     * registered deck is the draft's league.
     *
     * Late linkage is positive-evidence, unlike AssignLeague's "do not
     * split" default: RegisteredDeckMatchesDraftPool carries that check,
     * and a set-code/date pre-filter narrows the candidates first.
     */
    public static function run(): void
    {
        $unlinked = Draft::query()
            ->whereNull('league_id')
            ->has('picks', '>=', self::MIN_PICKS_FOR_LINKAGE)
            ->where('created_at', '>', now()->subDays(30))
            ->limit(20)
            ->get();

        if ($unlinked->isEmpty()) {
            return;
        }

        $candidates = League::query()
            ->where('kind', LeagueKind::Draft)
            ->whereDoesntHave('draft')
            ->where('started_at', '>', now()->subDays(30))
            ->limit(20)
            ->with('deckSnapshots')
            ->get();

        foreach ($unlinked as $draft) {
            $draftSetCode = self::draftSetCode($draft);

            foreach ($candidates as $league) {
                if ($draftSetCode && $league->set_code && $draftSetCode !== $league->set_code) {
                    continue;
                }

                /** Carbon 3 returns a signed difference; direction is irrelevant here. */
                if ($draft->started_at && $league->started_at && abs($draft->started_at->diffInDays($league->started_at)) > 7) {
                    continue;
                }

                if (RegisteredDeckMatchesDraftPool::run($draft, $league)) {
                    $draft->update(['league_id' => $league->id]);
                    $candidates = $candidates->reject(fn (League $l) => $l->id === $league->id);
                    break;
                }
            }
        }
    }

    /**
     * Mode of set_code across the draft's picked cards, mirroring
     * ResolveLeagueSetCode::fromPicks.
     */
    private static function draftSetCode(Draft $draft): ?string
    {
        $ids = $draft->picks()->whereNotNull('picked_catalog_id')->pluck('picked_catalog_id');
        if ($ids->isEmpty()) {
            return null;
        }

        return Card::query()
            ->whereIn('mtgo_id', $ids->map(fn ($id) => (string) $id))
            ->whereNotNull('set_code')
            ->pluck('set_code')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();
    }
}
