<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateColorIdentityController extends Controller
{
    private const ORDER = ['W', 'U', 'B', 'R', 'G', 'C'];

    public function __invoke(Deck $deck, Request $request): RedirectResponse
    {
        abort_if($deck->trashed(), 403, 'This deck has been deleted on MTGO and is read-only.');

        $data = $request->validate([
            'color_identity' => 'nullable|array|max:6',
            'color_identity.*' => ['string', Rule::in(self::ORDER)],
        ]);

        $colors = collect($data['color_identity'] ?? [])
            ->unique()
            ->sortBy(fn (string $color) => array_search($color, self::ORDER, true))
            ->values();

        $deck->update([
            'color_identity' => $colors->isEmpty() ? null : $colors->join(','),
        ]);

        return back();
    }
}
