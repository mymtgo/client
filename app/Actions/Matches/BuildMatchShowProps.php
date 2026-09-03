<?php

namespace App\Actions\Matches;

use App\Actions\Archetypes\GetArchetypeOptions;
use App\Actions\Import\ExtractCardsFromGameLog;
use App\Data\Front\MatchData;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;

class BuildMatchShowProps
{
    /**
     * Everything the match detail view needs, independent of which layout
     * wraps it: the deck-view match page and the limited event match page
     * render the same body from this payload.
     *
     * @return array{match: MatchData, games: Collection<int, mixed>, gameLogs: Collection<int, mixed>, archetypes: mixed, imported: bool}
     */
    public static function run(MtgoMatch $match): array
    {
        $match->loadMissing([
            'games.players',
            'games.timeline',
            'opponentArchetypes.archetype',
            'opponentArchetypes.player',
            'deck.cover',
            'deck.archetype',
            'league',
        ]);

        if (! array_key_exists('games_won_count', $match->getAttributes())) {
            $match->loadCount([
                'games as games_won_count' => fn ($q) => $q->where('won', true),
                'games as games_lost_count' => fn ($q) => $q->where('won', false),
            ]);
        }

        $deckVersion = DeckVersion::find($match->deck_version_id);
        $registeredCards = $deckVersion->cards ?? [];

        // Per-game opponent cards extracted from the game log. Catches cards
        // the final GameCards snapshot misses (left visible zones, or logged
        // under a multi-face printing's face CatalogID).
        $logEntries = EnsureGameLogForMatch::run($match->token);
        $logCardData = ! empty($logEntries) ? ExtractCardsFromGameLog::run($logEntries) : null;

        $deckMtgoIds = $match->games->flatMap(fn ($game) => $game->players->flatMap(
            fn ($player) => collect($player->pivot->deck_json)->pluck('mtgo_id')
        ));

        $timelineCatalogIds = $match->games->flatMap(
            fn ($game) => $game->timeline->flatMap(
                fn ($snapshot) => collect($snapshot->content['Cards'] ?? [])->pluck('CatalogID')
            )
        );

        $logCardMtgoIds = collect($logCardData['cards_by_game'] ?? [])->flatMap(
            fn ($byPlayer) => collect($byPlayer)->flatMap(fn ($cards) => collect($cards)->pluck('mtgo_id'))
        );

        $allMtgoIds = $deckMtgoIds->merge($timelineCatalogIds)->merge($logCardMtgoIds)->unique();
        $cardsByMtgoId = Card::whereIn('mtgo_id', $allMtgoIds)->get()->keyBy('mtgo_id');

        $registeredOracleIds = collect($registeredCards)->pluck('oracle_id')->filter()->unique();
        $cardsByOracleId = Card::whereIn('oracle_id', $registeredOracleIds)->get()->keyBy('oracle_id');

        $sortedGames = $match->games->sortBy('started_at')->values();

        $games = $sortedGames->map(function ($game, $index) use ($cardsByMtgoId, $cardsByOracleId, $registeredCards, $logCardData) {
            $opponentName = $game->players->first(fn ($p) => ! $p->pivot->is_local)?->username;
            $opponentLogCards = $logCardData['cards_by_game'][$index][$opponentName] ?? [];

            return BuildMatchGameData::run(
                $game, $index + 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards, $opponentLogCards
            );
        });

        $gameLogs = $sortedGames->mapWithKeys(fn ($game) => [
            $game->id => GetGameLogEntries::run($game),
        ]);

        return [
            'match' => MatchData::from($match),
            'games' => $games,
            'gameLogs' => $gameLogs,
            'archetypes' => GetArchetypeOptions::run(),
            'imported' => (bool) $match->imported,
        ];
    }
}
