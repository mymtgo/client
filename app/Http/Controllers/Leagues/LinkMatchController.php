<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkMatchController extends Controller
{
    public function __invoke(Request $request, League $league): RedirectResponse
    {
        $validated = $request->validate([
            'match_id' => ['sometimes', 'integer', 'exists:matches,id'],
            'match_ids' => ['sometimes', 'array', 'min:1'],
            'match_ids.*' => ['integer', 'exists:matches,id'],
        ]);

        $ids = collect($validated['match_ids'] ?? [])
            ->when(isset($validated['match_id']), fn ($c) => $c->push($validated['match_id']))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['match_id' => 'No matches selected.']);
        }

        if (! $league->manual) {
            throw ValidationException::withMessages(['match_id' => 'League is not manual.']);
        }

        $existingCount = $league->matches()->count();

        if ($existingCount + $ids->count() > 5) {
            throw ValidationException::withMessages([
                'match_id' => 'League would exceed 5 matches.',
            ]);
        }

        $matches = MtgoMatch::query()->whereIn('id', $ids)->get();

        if ($matches->count() !== $ids->count()) {
            throw ValidationException::withMessages(['match_id' => 'One or more matches not found.']);
        }

        $leagueDeckId = DeckVersion::query()->whereKey($league->deck_version_id)->value('deck_id');

        foreach ($matches as $match) {
            if ($match->league_id !== null) {
                throw ValidationException::withMessages(['match_id' => 'A selected match already belongs to a league.']);
            }

            if ($match->state !== MatchState::Complete) {
                throw ValidationException::withMessages(['match_id' => 'A selected match is not complete.']);
            }

            $matchDeckId = DeckVersion::query()->whereKey($match->deck_version_id)->value('deck_id');

            if ($leagueDeckId === null || $matchDeckId === null || $leagueDeckId !== $matchDeckId) {
                throw ValidationException::withMessages(['match_id' => 'A selected match does not match the league deck.']);
            }
        }

        DB::transaction(function () use ($matches, $league) {
            foreach ($matches as $match) {
                $match->update(['league_id' => $league->id]);
            }
        });

        return redirect()->back();
    }
}
