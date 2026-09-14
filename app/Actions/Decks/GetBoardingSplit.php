<?php

namespace App\Actions\Decks;

use App\Actions\Util\Winrate;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use Carbon\Carbon;

class GetBoardingSplit
{
    /**
     * Win rate in the first game of a match against every game after it.
     *
     * Game one is played blind; everything after it is played with a
     * sideboard and knowledge of the opponent, so the gap between the two is
     * the closest thing to a measure of how well the sideboard is built.
     *
     * The game number comes from when a game started rather than from its id,
     * because games are written as their logs are projected and a match's
     * rows do not reliably land in play order. Matches imported without game
     * rows contribute nothing: they carry a match-level score only, which
     * says nothing about which game was which.
     *
     * @return array{gameOneWon: int, gameOneLost: int, gameOneRate: int, postBoardWon: int, postBoardLost: int, postBoardRate: int, delta: int, games: int}
     */
    public static function run(Deck $deck, Carbon $from, Carbon $to, ?DeckVersion $deckVersion = null): array
    {
        $numbered = Game::query()
            ->join('matches as m', 'm.id', '=', 'games.match_id')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->where('dv.deck_id', $deck->id)
            ->where('m.state', MatchState::Complete->value)
            ->whereBetween('m.started_at', [$from, $to])
            ->when($deckVersion, fn ($q) => $q->where('m.deck_version_id', $deckVersion->id))
            ->whereNotNull('games.won')
            ->selectRaw('games.won as won, ROW_NUMBER() OVER (PARTITION BY games.match_id ORDER BY games.started_at, games.id) as game_number')
            ->toBase();

        $row = Game::query()
            ->fromSub($numbered, 'numbered')
            ->toBase()
            ->selectRaw('
                SUM(CASE WHEN game_number = 1 AND won = 1 THEN 1 ELSE 0 END) as game_one_won,
                SUM(CASE WHEN game_number = 1 AND won = 0 THEN 1 ELSE 0 END) as game_one_lost,
                SUM(CASE WHEN game_number > 1 AND won = 1 THEN 1 ELSE 0 END) as post_board_won,
                SUM(CASE WHEN game_number > 1 AND won = 0 THEN 1 ELSE 0 END) as post_board_lost
            ')
            ->first();

        $gameOneWon = (int) ($row->game_one_won ?? 0);
        $gameOneLost = (int) ($row->game_one_lost ?? 0);
        $postBoardWon = (int) ($row->post_board_won ?? 0);
        $postBoardLost = (int) ($row->post_board_lost ?? 0);

        $gameOneRate = Winrate::percentage($gameOneWon, $gameOneLost);
        $postBoardRate = Winrate::percentage($postBoardWon, $postBoardLost);

        return [
            'gameOneWon' => $gameOneWon,
            'gameOneLost' => $gameOneLost,
            'gameOneRate' => $gameOneRate,
            'postBoardWon' => $postBoardWon,
            'postBoardLost' => $postBoardLost,
            'postBoardRate' => $postBoardRate,
            'delta' => $postBoardRate - $gameOneRate,
            'games' => $gameOneWon + $gameOneLost + $postBoardWon + $postBoardLost,
        ];
    }
}
