<?php

namespace App\Http\Controllers\Matches;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Matches\BuildMatchGameData;
use App\Actions\Matches\GetGameLogEntries;
use App\Data\Front\ArchetypeData;
use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
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
        $registeredCards = $deckVersion->cards ?? [];

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

        $sortedGames = $match->games->sortBy('started_at')->values();

        $games = $sortedGames->map(fn ($game, $index) => BuildMatchGameData::run(
            $game, $index + 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards
        ));

        // Game log entries per game (keyed by game ID)
        $gameLogs = $sortedGames->mapWithKeys(fn ($game) => [
            $game->id => GetGameLogEntries::run($game),
        ]);

        // Get deck sidebar props if match has a deck
        $deck = $deckVersion?->deck;
        $shared = $deck ? GetDeckViewSharedProps::run($deck) : [];

        return Inertia::render('matches/Show', [
            ...$shared,
            'currentPage' => 'matches',
            'match' => MatchData::from($match),
            'games' => $games,
            'gameLogs' => $gameLogs,
            'archetypes' => ArchetypeData::collect(Archetype::orderBy('name')->get()),
        ]);
    }
}
