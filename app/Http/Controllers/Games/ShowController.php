<?php

namespace App\Http\Controllers\Games;

use App\Actions\Matches\GetGameLogEntries;
use App\Actions\Replays\BuildReplayFrames;
use App\Actions\Replays\ShareReplay;
use App\Data\Front\GameTimelineData;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Game;
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

        return Inertia::render('games/Show', [
            'game' => $game,
            'timeline' => GameTimelineData::collect($events),
            'gameLog' => GetGameLogEntries::run($game),
            'matchGames' => $this->matchGames($game),
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
}
