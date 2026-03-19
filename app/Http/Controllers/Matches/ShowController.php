<?php

namespace App\Http\Controllers\Matches;

use App\Actions\Matches\BuildMatchGameData;
use App\Data\Front\ArchetypeData;
use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id)
    {
        $match = MtgoMatch::with([
            'games.players',
            'games.timeline',
            'opponentArchetypes.archetype',
            'opponentArchetypes.player',
            'deck',
            'league',
        ])->find($id);

        if (! $match) {
            return redirect()->route('home');
        }

        $deckVersion = DeckVersion::find($match->deck_version_id);
        $registeredCards = $deckVersion?->cards ?? [];

        // Batch all mtgo_ids: deck_json entries + timeline CatalogIDs
        $deckMtgoIds = $match->games->flatMap(fn ($game) => $game->players->flatMap(
            fn ($player) => collect($player->pivot->deck_json)->pluck('mtgo_id')
        ));

        $timelineCatalogIds = $match->games->flatMap(
            fn ($game) => $game->timeline->flatMap(
                fn ($snapshot) => collect($snapshot->content['Cards'] ?? [])->pluck('CatalogID')
            )
        );

        $allMtgoIds = $deckMtgoIds->merge($timelineCatalogIds)->unique();
        $cardsByMtgoId = Card::whereIn('mtgo_id', $allMtgoIds)->get()->keyBy('mtgo_id');

        $registeredOracleIds = collect($registeredCards)->pluck('oracle_id')->filter()->unique();
        $cardsByOracleId = Card::whereIn('oracle_id', $registeredOracleIds)->get()->keyBy('oracle_id');

        $games = $match->games
            ->sortBy('started_at')
            ->values()
            ->map(fn ($game, $index) => BuildMatchGameData::run(
                $game, $index + 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards
            ));

        return Inertia::render('matches/Show', [
            'match' => MatchData::from($match),
            'games' => $games,
            'archetypes' => ArchetypeData::collect(Archetype::orderBy('name')->get()),
        ]);
    }
}
