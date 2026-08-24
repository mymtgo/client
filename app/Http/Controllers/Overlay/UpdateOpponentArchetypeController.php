<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Archetypes\ResolveMergedArchetype;
use App\Actions\Overlay\ResolveOverlayOpponent;
use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Jobs\DownloadArchetypeDecklists;
use App\Models\Archetype;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateOpponentArchetypeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'archetype_id' => 'required|exists:archetypes,id',
        ]);

        $match = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->latest('started_at')
            ->first();

        $opponent = $match ? ResolveOverlayOpponent::findOpponent($match) : null;

        if (! $match || ! $opponent) {
            return back();
        }

        $resolved = ResolveMergedArchetype::run((int) $validated['archetype_id'], null);

        MatchArchetype::updateOrCreate(
            [
                'mtgo_match_id' => $match->id,
                'player_id' => $opponent->id,
            ],
            [
                'archetype_id' => $resolved['archetype_id'],
                'archetype_deck_id' => $resolved['archetype_deck_id'],
                'confidence' => 1.0,
                'manual' => true,
            ]
        );

        /**
         * Pull the picked archetype's decklists so the local estimator can
         * recognise it unaided next time. Fallback archetypes have no
         * decklists to fetch.
         */
        if (! Archetype::query()->whereKey($resolved['archetype_id'])->value('is_fallback')) {
            DownloadArchetypeDecklists::dispatch($resolved['archetype_id']);
        }

        return back();
    }
}
