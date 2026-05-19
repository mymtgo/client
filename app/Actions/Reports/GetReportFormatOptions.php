<?php

namespace App\Actions\Reports;

use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;

class GetReportFormatOptions
{
    /**
     * Return formats present in completed matches for a given archetype on the active account.
     *
     * Used to populate the format dropdown after the user selects an archetype in the Reports selector.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    public static function run(int $archetypeId): Collection
    {
        $accountId = Account::currentId();
        $deckTable = (new Deck)->getTable();
        $versionTable = (new DeckVersion)->getTable();
        $matchTable = (new MtgoMatch)->getTable();

        return MtgoMatch::query()
            ->from($matchTable.' as m')
            ->join($versionTable.' as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join($deckTable.' as d', 'd.id', '=', 'dv.deck_id')
            ->where('m.state', MatchState::Complete)
            ->where('d.archetype_id', $archetypeId)
            ->when($accountId, fn ($q) => $q->where('d.account_id', $accountId))
            ->distinct()
            ->pluck('m.format')
            ->filter()
            ->sort()
            ->values()
            ->map(fn (string $format) => [
                'value' => $format,
                'label' => MtgoMatch::displayFormat($format),
            ]);
    }
}
