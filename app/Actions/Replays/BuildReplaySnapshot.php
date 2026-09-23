<?php

namespace App\Actions\Replays;

use App\Actions\Matches\GetGameLogEntries;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Support\MtgoFormat;
use Mymtgo\Replay\ReplaySnapshot;

class BuildReplaySnapshot
{
    /**
     * One match as a self-contained replay: every game that has frames, each
     * with remote card images, its game log and its result, plus the head
     * metadata the website shows. Unredacted; ShareReplay removes the
     * opponent's name.
     *
     * Game numbers are positions among all of the match's games, the same
     * numbering the desktop picker uses, so a game with no recorded frames
     * leaves a gap rather than renumbering the rest.
     *
     * @return array<string, mixed>
     */
    public static function run(MtgoMatch $match): array
    {
        $match->loadMissing(['deck.archetype', 'opponentArchetypes.archetype']);
        $games = self::games($match);
        $format = MtgoFormat::key($match->format);

        $snapshots = [];

        foreach ($games as $index => $game) {
            $frames = BuildReplayFrames::run($game, portableImages: true);

            if ($frames === []) {
                continue;
            }

            $snapshots[] = [
                'game_number' => $index + 1,
                'won' => $game->won === null ? null : (bool) $game->won,
                'frames' => $frames,
                'log' => array_values(GetGameLogEntries::run($game)),
            ];
        }

        $first = $games[0] ?? null;

        return [
            'version' => ReplaySnapshot::VERSION,
            'meta' => [
                'format' => $format === '' ? null : $format,
                'played_at' => ($first?->started_at ?? $match->started_at ?? $match->created_at)->toIso8601String(),
                'games_in_match' => max(1, count($games)),
                'local_archetype' => $match->deck?->archetype?->name,
                'opponent_archetype' => $match->opponentArchetypes->first()?->archetype?->name,
            ],
            'games' => $snapshots,
        ];
    }

    /** A game's place in its match, 1-based, in the replay picker's order. */
    public static function gameNumber(Game $game): int
    {
        $index = array_search($game->id, array_map(fn (Game $sibling) => $sibling->id, self::games($game->match)), true);

        return $index === false ? 1 : $index + 1;
    }

    /** @return list<Game> */
    private static function games(MtgoMatch $match): array
    {
        return Game::where('match_id', $match->id)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
