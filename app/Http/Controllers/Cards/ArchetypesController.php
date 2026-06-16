<?php

namespace App\Http\Controllers\Cards;

use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\MtgoMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArchetypesController extends Controller
{
    /**
     * List the archetypes a card (and all its printings) appears in, noting
     * whether it shows up in the maindeck, the sideboard, or both.
     *
     * The `{group}` segment is the card's oracle_id, or `id:{id}` for printings
     * that have no oracle_id yet — mirroring the index page's grouping key. An
     * optional `format` query scopes results to that format, matching the index
     * page's active filter.
     */
    public function __invoke(Request $request, string $group): JsonResponse
    {
        $format = (string) $request->input('format', '');

        $cardIds = str_starts_with($group, 'id:')
            ? [(int) substr($group, 3)]
            : Card::query()->where('oracle_id', $group)->pluck('id')->all();

        $archetypes = Archetype::query()
            ->join('archetype_decks as ad', 'ad.archetype_id', '=', 'archetypes.id')
            ->join('archetype_deck_cards as adc', 'adc.archetype_deck_id', '=', 'ad.id')
            ->whereIn('adc.card_id', $cardIds)
            ->when($format !== '', fn ($q) => $q->where('archetypes.format', $format))
            ->groupBy('archetypes.id', 'archetypes.name', 'archetypes.format', 'archetypes.color_identity')
            ->select('archetypes.id', 'archetypes.name', 'archetypes.format', 'archetypes.color_identity')
            ->selectRaw('max(case when adc.sideboard = 0 then 1 else 0 end) as in_maindeck')
            ->selectRaw('max(case when adc.sideboard = 1 then 1 else 0 end) as in_sideboard')
            ->selectRaw('count(distinct ad.id) as deck_count')
            ->orderByDesc('deck_count')
            ->orderBy('archetypes.name')
            ->get()
            ->map(fn (Archetype $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'format' => $a->format,
                'formatLabel' => $a->format ? MtgoMatch::displayFormat($a->format) : null,
                'colorIdentity' => $a->color_identity,
                'maindeck' => (bool) $a->in_maindeck,
                'sideboard' => (bool) $a->in_sideboard,
                'deckCount' => (int) $a->deck_count,
            ]);

        return response()->json(['archetypes' => $archetypes]);
    }
}
