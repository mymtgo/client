<?php

namespace App\Actions\Limited\Read;

use App\Data\Front\LimitedEventData;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Deck;
use App\Models\League;
use Illuminate\Support\Collection;

class GetLimitedEventSharedProps
{
    /**
     * Props shared by every Limited event sub-page (the sidebar).
     *
     * @return array{event: LimitedEventData}
     */
    public static function run(League $league): array
    {
        $league->loadMissing(['draft', 'deckSnapshots']);
        $draft = $league->draft;

        $matches = $league->matches()->where('state', MatchState::Complete)->get(['outcome']);
        $wins = $matches->where('outcome', MatchOutcome::Win)->count();
        $losses = $matches->where('outcome', MatchOutcome::Loss)->count();

        $pickedAll = $draft
            ? $draft->picks()->whereNotNull('picked_catalog_id')->orderBy('ordinal')->pluck('picked_catalog_id')->map(fn ($id) => (int) $id)
            : collect();
        $picksMade = $pickedAll->count();
        $pickedIds = $pickedAll->unique()->values()->all();
        $cards = ResolveCatalogCards::run($pickedIds);
        $setName = $cards->first(fn (Card $c) => $c->set_name !== null)?->set_name;
        $cover = self::coverArt($pickedIds, $cards);

        $deck = $draft
            ? Deck::withTrashed()->where('mtgo_id', 'limited:'.$draft->draft_token)->first()
            : Deck::withTrashed()->where('mtgo_id', 'limited:league-'.$league->id)->first();

        [$state, $variant] = LeagueStateBadge::run($league, $draft, $matches->count());
        $kindLabel = ucfirst($league->kind->value);
        $date = $league->started_at?->format('j M');

        return [
            'event' => new LimitedEventData(
                id: $league->id,
                draftId: $draft?->id,
                title: trim(($league->set_code ?? '').' '.$kindLabel.($date ? ' · '.$date : '')),
                subtitle: trim(($setName ?? 'Unknown set').($league->event_id ? ' · League '.$league->event_id : '')),
                setCode: $league->set_code,
                setName: $setName,
                kind: $league->kind->value,
                state: $state,
                stateVariant: $variant,
                startedAt: $league->started_at?->toIso8601String(),
                startedAtHuman: $league->started_at?->format('j M Y · H:i'),
                wins: $wins,
                losses: $losses,
                picksMade: $picksMade,
                picksExpected: (int) ($draft?->picks_expected ?? 42),
                deckRegistered: $league->deckSnapshots->where('source', 'registered')->isNotEmpty(),
                deckId: $deck?->id,
                coverArt: $cover,
                seatIndex: $draft?->seat_index,
                seatCount: (int) ($draft?->seat_count ?? 8),
                boosterCatalogId: $draft?->booster_catalog_id,
                draftState: $draft?->state->value ?? 'none',
            ),
        ];
    }

    /**
     * Art crop of the first rare or mythic picked (in pick order), else the
     * first pick with art.
     *
     * @param  array<int, int>  $pickedIds
     * @param  Collection<string, Card>  $cards
     */
    private static function coverArt(array $pickedIds, Collection $cards): ?string
    {
        foreach ($pickedIds as $id) {
            $card = $cards->get((string) $id);
            if ($card && in_array($card->rarity, ['rare', 'mythic'], true) && $card->art_crop_url) {
                return $card->art_crop_url;
            }
        }

        foreach ($pickedIds as $id) {
            $card = $cards->get((string) $id);
            if ($card?->art_crop_url) {
                return $card->art_crop_url;
            }
        }

        return null;
    }
}
