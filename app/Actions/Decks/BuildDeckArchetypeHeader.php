<?php

namespace App\Actions\Decks;

use App\Data\Front\ArchetypeData;
use App\Data\Front\DeckArchetypeHeaderData;
use App\Data\Front\MatchupSummaryData;
use App\Models\Archetype;
use App\Models\DeckVersion;
use App\Support\MatchRecord;

/**
 * Header band for the deck listing when one archetype (or Unclassified) is
 * selected. The record is the sidebar row's record: same scoped deck set,
 * same Complete-state matches, draws in the total.
 */
class BuildDeckArchetypeHeader
{
    public const MIN_MATCHUP_MATCHES = 3;

    public static function run(string $archetypeFilter, ?string $format, bool $hideDeleted): ?DeckArchetypeHeaderData
    {
        if ($archetypeFilter === '') {
            return null;
        }

        $unclassified = $archetypeFilter === 'none';

        $decks = BuildDeckSidebarOptions::scopedDecks($format, $hideDeleted)
            ->when($unclassified, fn ($q) => $q->whereNull('decks.archetype_id'))
            ->when(! $unclassified, fn ($q) => $q->where('decks.archetype_id', (int) $archetypeFilter))
            ->withCount(['wonMatches', 'lostMatches', 'matches'])
            ->get();

        if ($decks->isEmpty()) {
            return null;
        }

        $record = $decks->reduce(
            fn (MatchRecord $carry, $deck) => $carry->add(MatchRecord::fromTotal(
                (int) $deck->won_matches_count,
                (int) $deck->lost_matches_count,
                (int) $deck->matches_count,
            )),
            MatchRecord::empty(),
        );

        $archetype = $unclassified ? null : Archetype::query()->withExists('decks')->find((int) $archetypeFilter);

        [$best, $worst] = $unclassified ? [null, null] : self::matchups($decks->pluck('id')->all());

        return new DeckArchetypeHeaderData(
            archetype: $archetype ? ArchetypeData::fromModel($archetype) : null,
            deckCount: $decks->count(),
            record: $record->toData(),
            bestMatchup: $best,
            worstMatchup: $worst,
        );
    }

    /**
     * @param  array<int, int>  $deckIds
     * @return array{0: ?MatchupSummaryData, 1: ?MatchupSummaryData}
     */
    protected static function matchups(array $deckIds): array
    {
        $versionIds = DeckVersion::query()->whereIn('deck_id', $deckIds)->pluck('id')->all();

        $qualifying = GetArchetypeMatchupSpread::forVersionIds($versionIds, null, null)
            ->filter(fn (array $row) => $row['matches'] >= self::MIN_MATCHUP_MATCHES);

        if ($qualifying->count() < 2) {
            return [null, null];
        }

        $best = $qualifying->sortBy([['match_winrate', 'desc'], ['matches', 'desc']])->first();
        $worst = $qualifying->sortBy([['match_winrate', 'asc'], ['matches', 'desc']])->first();

        return [self::summary($best), self::summary($worst)];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function summary(array $row): MatchupSummaryData
    {
        return new MatchupSummaryData(
            archetypeId: (int) $row['archetype_id'],
            name: $row['name'],
            colorIdentity: $row['color_identity'],
            winrate: (int) $row['match_winrate'],
            matches: (int) $row['matches'],
        );
    }
}
