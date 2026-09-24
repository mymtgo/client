<?php

namespace App\Actions\Replays;

use App\Actions\Matches\GetGameLogEntries;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use Mymtgo\Replay\Actions\BuildReplaySnapshot as BuildSharedSnapshot;

class BuildReplaySnapshot
{
    /**
     * One match as a self-contained replay, built by the package builder the
     * website also uses, from this machine's own tables. Unredacted;
     * ShareReplay removes the opponent's name.
     *
     * The game log is only read for games that have frames, since the
     * builder drops the rest and reading the log means decoding the match's
     * .dat file.
     *
     * @return array<string, mixed>
     */
    public static function run(MtgoMatch $match): array
    {
        $match->loadMissing(['deck.archetype', 'opponentArchetypes.archetype']);
        $games = self::games($match);
        $first = $games[0] ?? null;

        return BuildSharedSnapshot::run([
            'format' => $match->format,
            'played_at' => ($first?->started_at ?? $match->started_at ?? $match->created_at)->toIso8601String(),
            'local_archetype' => $match->deck?->archetype?->name,
            'opponent_archetype' => $match->opponentArchetypes->first()?->archetype?->name,
            'games' => array_map(function (Game $game) {
                $timeline = GameTimeline::where('game_id', $game->id)->get()
                    ->map(fn (GameTimeline $event) => ['timestamp' => (string) $event->timestamp, 'content' => $event->content])
                    ->all();

                return [
                    'won' => $game->won === null ? null : (bool) $game->won,
                    'local_username' => $game->localPlayers->first()?->username,
                    'timeline' => $timeline,
                    'log' => $timeline === [] ? [] : array_values(GetGameLogEntries::run($game)),
                ];
            }, $games),
        ], self::cards(...));
    }

    /**
     * Name, type and remote image for each catalog id. The raw `image`
     * column, not the accessor, since the accessor prefers the local cache
     * served on localhost, which is dead anywhere but this machine.
     *
     * @param  list<int>  $catalogIds
     * @return array<int, array{name: ?string, type: ?string, image: ?string}>
     */
    private static function cards(array $catalogIds): array
    {
        return Card::whereIn('mtgo_id', $catalogIds)->get()
            ->mapWithKeys(fn (Card $card) => [(int) $card->mtgo_id => [
                'name' => $card->name,
                'type' => $card->type,
                'image' => $card->getRawOriginal('image'),
            ]])
            ->all();
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
