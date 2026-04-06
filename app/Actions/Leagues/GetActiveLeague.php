<?php

namespace App\Actions\Leagues;

use App\Enums\MatchState;
use App\Models\Account;
use App\Models\League;
use App\Models\MtgoMatch;

class GetActiveLeague
{
    /**
     * Get the most recent league run for the dashboard widget.
     */
    public static function run(): ?array
    {
        $accountId = Account::active()->value('id');

        $league = League::whereHas('matches', function ($q) use ($accountId) {
            $q->where('state', MatchState::Complete);
            if ($accountId) {
                $q->whereHas('deckVersion', fn ($dv) => $dv->whereHas('deck', fn ($d) => $d->where('account_id', $accountId)));
            }
        })
            ->with(['deckVersion.deck.cover'])
            ->latest('started_at')
            ->first();

        if (! $league) {
            return null;
        }

        $matches = MtgoMatch::complete()->where('league_id', $league->id)
            ->with(['deck'])
            ->latest('started_at')
            ->take(5)
            ->get()
            ->reverse()
            ->values();

        $wins = $matches->filter(fn ($m) => $m->isWin())->count();
        $losses = $matches->filter(fn ($m) => $m->isLoss())->count();

        $versionLabel = null;
        if ($league->deckVersion) {
            $versionIndex = $league->deckVersion->deck->versions()
                ->where('modified_at', '<=', $league->deckVersion->modified_at)
                ->count();
            $versionLabel = 'v'.$versionIndex;
        }

        return [
            'name' => $league->name,
            'format' => MtgoMatch::displayFormat($league->format),
            'phantom' => $league->phantom,
            'isActive' => $matches->count() < 5,
            'isTrophy' => $wins === 5,
            'deckName' => $league->deckVersion?->deck->name ?? $matches->last()?->getRelation('deck')->getAttribute('name'),
            'versionLabel' => $versionLabel,
            'results' => $matches
                ->map(fn ($m) => $m->isWin() ? 'W' : 'L')
                ->pad(5, null)
                ->values()
                ->toArray(),
            'wins' => $wins,
            'losses' => $losses,
            'matchesRemaining' => 5 - $matches->count(),
        ];
    }
}
