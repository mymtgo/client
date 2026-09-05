<?php

namespace App\Actions\SideboardGuides;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Data\Front\SideboardGuideSummaryData;
use App\Enums\SideboardDirection;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\SideboardGuide;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class GetSideboardGuideSummaries
{
    /**
     * One row per guide on the deck, with the deck's all-time record against
     * that archetype so the listing doubles as a scoreboard.
     *
     * @return array<int, SideboardGuideSummaryData>
     */
    public static function run(Deck $deck): array
    {
        $guides = SideboardGuide::query()
            ->where('deck_id', $deck->id)
            ->with(['archetype', 'cards'])
            ->get()
            ->sortBy(fn (SideboardGuide $guide) => $guide->archetype->name)
            ->values();

        return self::summarise($deck, $guides)->all();
    }

    public static function forGuide(SideboardGuide $guide): SideboardGuideSummaryData
    {
        $guide->loadMissing(['deck', 'archetype', 'cards']);

        return self::summarise($guide->deck, EloquentCollection::make([$guide]))->first();
    }

    /**
     * @param  EloquentCollection<int, SideboardGuide>  $guides
     * @return Collection<int, SideboardGuideSummaryData>
     */
    private static function summarise(Deck $deck, EloquentCollection $guides): Collection
    {
        if ($guides->isEmpty()) {
            return collect();
        }

        $spread = GetArchetypeMatchupSpread::run($deck, null, null)->keyBy('archetype_id');

        $noteCounts = DeckArchetypeNote::query()
            ->where('deck_id', $deck->id)
            ->whereIn('archetype_id', $guides->pluck('archetype_id'))
            ->selectRaw('archetype_id, COUNT(*) as aggregate')
            ->groupBy('archetype_id')
            ->pluck('aggregate', 'archetype_id');

        return $guides->map(function (SideboardGuide $guide) use ($spread, $noteCounts) {
            $matchup = $spread->get($guide->archetype_id);

            return new SideboardGuideSummaryData(
                id: $guide->id,
                archetypeId: $guide->archetype_id,
                archetypeName: $guide->archetype->name,
                archetypeColorIdentity: $guide->archetype->color_identity,
                cardsIn: (int) $guide->cards->where('direction', SideboardDirection::In)->sum('quantity'),
                cardsOut: (int) $guide->cards->where('direction', SideboardDirection::Out)->sum('quantity'),
                notesCount: (int) ($noteCounts[$guide->archetype_id] ?? 0),
                updatedAt: $guide->updated_at,
                matches: (int) ($matchup['matches'] ?? 0),
                matchRecord: $matchup['match_record'] ?? null,
                matchWinrate: $matchup === null ? null : (int) $matchup['match_winrate'],
                gameWinrate: $matchup === null ? null : (int) $matchup['game_winrate'],
            );
        })->values();
    }
}
