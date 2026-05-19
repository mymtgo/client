<?php

namespace App\Actions\Reports;

use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;

class GetReportArchetypeOptions
{
    /**
     * Return archetypes eligible for Reports selection.
     *
     * Only includes archetypes where:
     *   - the active account has at least one deck tagged with that archetype
     *   - that deck has at least one complete match
     *   - the archetype is not merged into another (merged_into_id IS NULL)
     *
     * Soft-deleted decks are intentionally included to match Deck::forActiveAccount() semantics.
     *
     * @return Collection<int, array{id: int, name: string, colorIdentity: string|null}>
     */
    public static function run(): Collection
    {
        $accountId = Account::currentId();
        $deckTable = (new Deck)->getTable();
        $versionTable = (new DeckVersion)->getTable();
        $matchTable = (new MtgoMatch)->getTable();

        return Archetype::query()
            ->whereNull('merged_into_id')
            ->whereExists(function ($query) use ($accountId, $deckTable, $versionTable, $matchTable) {
                $query->select('id')
                    ->from($deckTable)
                    ->whereColumn($deckTable.'.archetype_id', 'archetypes.id')
                    ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
                    ->whereExists(function ($vQuery) use ($deckTable, $versionTable, $matchTable) {
                        $vQuery->select('id')
                            ->from($versionTable)
                            ->whereColumn($versionTable.'.deck_id', $deckTable.'.id')
                            ->whereExists(function ($mQuery) use ($versionTable, $matchTable) {
                                $mQuery->select('id')
                                    ->from($matchTable)
                                    ->whereColumn($matchTable.'.deck_version_id', $versionTable.'.id')
                                    ->where($matchTable.'.state', MatchState::Complete);
                            });
                    });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Archetype $archetype) => [
                'id' => $archetype->id,
                'name' => $archetype->name,
                'colorIdentity' => $archetype->color_identity,
            ]);
    }
}
