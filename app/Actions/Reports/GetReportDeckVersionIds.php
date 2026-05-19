<?php

namespace App\Actions\Reports;

use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetReportDeckVersionIds
{
    /**
     * Resolve the list of deck-version ids contributing to a Reports query.
     *
     * Soft-deleted decks are intentionally included to match Deck::forActiveAccount() semantics.
     *
     * @return array<int, int>
     */
    public static function run(int $archetypeId, string $format, ?Carbon $from, ?Carbon $to): array
    {
        $accountId = Account::currentId();
        $deckTable = (new Deck)->getTable();
        $versionTable = (new DeckVersion)->getTable();
        $matchTable = (new MtgoMatch)->getTable();

        $query = DeckVersion::query()
            ->from($versionTable.' as dv')
            ->join($deckTable.' as d', 'd.id', '=', 'dv.deck_id')
            ->join($matchTable.' as m', 'm.deck_version_id', '=', 'dv.id')
            ->where('d.archetype_id', $archetypeId)
            ->where('m.state', MatchState::Complete)
            ->where('m.format', $format)
            ->when($accountId, fn ($q) => $q->where('d.account_id', $accountId));

        if ($from && $to) {
            $query->whereBetween('m.started_at', [$from, $to]);
        }

        return $query
            ->distinct()
            ->pluck('dv.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
