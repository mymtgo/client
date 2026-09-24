<?php

namespace App\Http\Controllers\Games;

use App\Actions\Matches\GetGameLogEntries;
use App\Actions\Replays\BuildReplayFrames;
use App\Actions\Replays\GetLocalSideboard;
use App\Actions\Replays\ShareReplay;
use App\Data\Front\GameTimelineData;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Services\Sync\SyncTokens;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id)
    {
        $game = Game::findOrFail($id);
        $events = collect(BuildReplayFrames::run($game))->map(fn (array $frame) => new GameTimelineData(
            timestamp: $frame['timestamp'],
            content: $frame['content'],
        ));

        $matchGames = $this->matchGames($game);

        return Inertia::render('games/Show', [
            'game' => $game,
            'timeline' => GameTimelineData::collect($events),
            'gameLog' => GetGameLogEntries::run($game),
            'matchGames' => $matchGames,
            'sideboard' => $this->sideboard($game),
            'previousSideboard' => $this->previousSideboard($game, $matchGames),
            'share' => [
                'linked' => app(SyncTokens::class)->linked(),
                'supporter' => AppSettings::isSupporter(),
                'url' => $game->match->replay_share_url === null ? null : ShareReplay::linkToGame($game->match->replay_share_url, $game),
            ],
        ]);
    }

    /**
     * Every game in the match in play order, so the replay can move between them.
     *
     * @return list<array{id: int, number: int, won: bool|null}>
     */
    private function matchGames(Game $game): array
    {
        return Game::where('match_id', $game->match_id)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get(['id', 'won'])
            ->values()
            ->map(fn (Game $sibling, int $index) => [
                'id' => $sibling->id,
                'number' => $index + 1,
                'won' => $sibling->won,
            ])
            ->all();
    }

    /**
     * Your sideboard as the nearest earlier recorded game of the match began,
     * so the replay can show what was sided in and out for this one.
     *
     * @param  list<array{id: int, number: int, won: bool|null}>  $matchGames
     * @return array{game: int, sideboard: list<array{catalog_id: int, quantity: int, name: ?string, type: ?string, image: ?string}>}|null
     */
    private function previousSideboard(Game $game, array $matchGames): ?array
    {
        $earlier = array_reverse(array_slice($matchGames, 0, (int) array_search($game->id, array_column($matchGames, 'id'), true)));

        foreach ($earlier as $sibling) {
            $sideboard = $this->sideboard(Game::findOrFail($sibling['id']));

            if ($sideboard !== null) {
                return ['game' => $sibling['number'], 'sideboard' => $sideboard];
            }
        }

        return null;
    }

    /**
     * Your sideboard as the game began, in the viewer's entry shape: from the
     * recorded deck, or failing that from the first frame that holds it, as
     * log-built frames do. Null when neither has it.
     *
     * @return list<array{catalog_id: int, quantity: int, name: ?string, type: ?string, image: ?string}>|null
     */
    private function sideboard(Game $game): ?array
    {
        $entries = GetLocalSideboard::run($game);

        if ($entries === []) {
            $entries = $this->sideboardFromFrames($game);
        }

        if ($entries === []) {
            return null;
        }

        $cards = Card::whereIn('mtgo_id', array_column($entries, 'mtgo_id'))->get()->keyBy(fn (Card $card) => (int) $card->mtgo_id);

        return array_map(fn (array $entry) => [
            'catalog_id' => $entry['mtgo_id'],
            'quantity' => $entry['quantity'],
            'name' => $cards->get($entry['mtgo_id'])?->name,
            'type' => $cards->get($entry['mtgo_id'])?->type,
            'image' => $cards->get($entry['mtgo_id'])?->image_url,
        ], $entries);
    }

    /**
     * The local player's sideboard cards in the first frame holding any,
     * counted per catalog id.
     *
     * @return list<array{mtgo_id: int, quantity: int}>
     */
    private function sideboardFromFrames(Game $game): array
    {
        $localName = $game->localPlayers->first()?->username;

        foreach (GameTimeline::where('game_id', $game->id)->orderBy('id')->lazyById() as $event) {
            $local = collect($event->content['Players'] ?? [])->firstWhere('Name', $localName)['Id'] ?? null;

            $counts = collect($event->content['Cards'] ?? [])
                ->filter(fn (array $card) => $local !== null && $card['Owner'] === $local
                    && ($card['Zone'] === 'Sideboard' || ($card['ActualZone'] ?? null) === 'Sideboard'))
                ->countBy('CatalogID');

            if ($counts->isNotEmpty()) {
                return $counts->map(fn (int $quantity, int $catalogId) => ['mtgo_id' => $catalogId, 'quantity' => $quantity])->values()->all();
            }
        }

        return [];
    }
}
