<?php

namespace App\Actions\Decks;

use App\Actions\Limited\EnsureLimitedDeckVersion;
use App\Data\Front\DeckArchetypeOptionData;
use App\Data\Front\DeckFormatOptionData;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\MtgoMatch;
use App\Support\ColorIdentity;
use App\Support\MatchRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\DataCollection;

/**
 * Sidebar counts for the deck listing. Every query starts from the same
 * scoped deck set the grid uses (active account, no Limited decks, archived
 * predicate), so the sidebar and the grid always agree.
 */
class BuildDeckSidebarOptions
{
    /**
     * @return DataCollection<int, DeckFormatOptionData>
     */
    public static function formatOptions(bool $hideDeleted): DataCollection
    {
        $rows = self::scopedDecks(null, $hideDeleted)
            ->select('format', DB::raw('COUNT(*) as deck_count'))
            ->groupBy('format')
            ->get();

        $options = $rows
            ->map(fn ($row) => new DeckFormatOptionData(
                value: $row->format,
                label: MtgoMatch::displayFormat($row->format),
                count: (int) $row->deck_count,
            ))
            ->sortBy(fn (DeckFormatOptionData $option) => $option->label)
            ->values();

        return DeckFormatOptionData::collect($options, DataCollection::class);
    }

    /**
     * One aggregate query. Matches are pre-aggregated per deck version in a
     * derived table so the matches table is scanned once; joined directly,
     * SQLite picks the `state` index and rescans every complete match for
     * every deck version. The Complete-state predicate lives in that derived
     * table so archetypes whose decks have no matches still appear with an
     * empty record.
     *
     * @return DataCollection<int, DeckArchetypeOptionData>
     */
    public static function archetypeOptions(?string $format, bool $hideDeleted): DataCollection
    {
        $matchesPerVersion = DB::table('matches')
            ->where('state', MatchState::Complete->value)
            ->groupBy('deck_version_id')
            ->select([
                'deck_version_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins"),
                DB::raw("SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses"),
            ]);

        $rows = self::scopedDecks($format, $hideDeleted)
            ->whereNotNull('decks.archetype_id')
            ->join('archetypes', 'archetypes.id', '=', 'decks.archetype_id')
            ->leftJoin('deck_versions', 'deck_versions.deck_id', '=', 'decks.id')
            ->leftJoinSub($matchesPerVersion, 'mv', 'mv.deck_version_id', '=', 'deck_versions.id')
            ->groupBy('archetypes.id', 'archetypes.name', 'archetypes.color_identity', 'archetypes.format')
            ->select([
                'archetypes.id',
                'archetypes.name',
                'archetypes.color_identity',
                'archetypes.format',
                DB::raw('COUNT(DISTINCT decks.id) as deck_count'),
                DB::raw('COALESCE(SUM(mv.total), 0) as total'),
                DB::raw('COALESCE(SUM(mv.wins), 0) as wins'),
                DB::raw('COALESCE(SUM(mv.losses), 0) as losses'),
            ])
            ->get();

        $options = $rows
            ->map(fn ($row) => new DeckArchetypeOptionData(
                id: (int) $row->id,
                name: $row->name,
                colorIdentity: ColorIdentity::normalize($row->color_identity),
                format: $row->format,
                deckCount: (int) $row->deck_count,
                record: MatchRecord::fromTotal((int) $row->wins, (int) $row->losses, (int) $row->total)->toData(),
            ))
            ->sortBy([
                fn (DeckArchetypeOptionData $a, DeckArchetypeOptionData $b) => $b->deckCount <=> $a->deckCount,
                fn (DeckArchetypeOptionData $a, DeckArchetypeOptionData $b) => strcasecmp($a->name, $b->name),
            ])
            ->values();

        return DeckArchetypeOptionData::collect($options, DataCollection::class);
    }

    public static function unclassifiedCount(?string $format, bool $hideDeleted): int
    {
        return self::scopedDecks($format, $hideDeleted)
            ->whereNull('decks.archetype_id')
            ->count();
    }

    /**
     * @return Builder<Deck>
     */
    public static function scopedDecks(?string $format, bool $hideDeleted): Builder
    {
        $query = Deck::forActiveAccount()
            ->where('decks.format', '!=', EnsureLimitedDeckVersion::FORMAT);

        if ($format !== null && $format !== '') {
            $query->where('decks.format', $format);
        }

        if ($hideDeleted) {
            $query->whereNull('decks.deleted_at');
        }

        return $query;
    }
}
