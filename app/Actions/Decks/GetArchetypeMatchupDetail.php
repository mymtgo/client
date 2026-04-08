<?php

namespace App\Actions\Decks;

use App\Actions\Util\Winrate;
use App\Models\AppSetting;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetArchetypeMatchupDetail
{
    /**
     * Return all data needed for the matchup detail drawer for a single archetype + deck combination.
     *
     * @return array{
     *     matchWinrate: int,
     *     gameWinrate: int,
     *     matchRecord: string,
     *     gameRecord: string,
     *     matches: int,
     *     otpWinrate: int,
     *     otpRecord: string,
     *     otdWinrate: int,
     *     otdRecord: string,
     *     avgTurns: float|null,
     *     avgMulligans: float|null,
     *     onPlayRate: int,
     *     bestCards: array,
     *     worstCards: array,
     *     matchHistory: array,
     * }
     */
    public static function run(
        Deck $deck,
        Archetype $archetype,
        ?Carbon $from,
        ?Carbon $to,
        ?DeckVersion $deckVersion = null,
    ): array {
        $matchIds = self::getMatchIds($deck, $archetype, $from, $to, $deckVersion);

        if ($matchIds->isEmpty()) {
            return self::emptyResult();
        }

        $stats = self::computeStats($matchIds->toArray());
        $playDraw = self::computePlayDrawStats($matchIds->toArray());
        $gameStats = self::computeGameStats($matchIds->toArray());
        $perGame = self::computePerGameWinrates($matchIds->toArray());
        $history = self::getMatchHistory($matchIds->toArray());

        return [
            'matchWinrate' => Winrate::percentage($stats->match_wins, $stats->match_losses),
            'gameWinrate' => Winrate::percentage($stats->games_won, $stats->games_lost),
            'matchRecord' => $stats->match_wins.' - '.$stats->match_losses,
            'gameRecord' => $stats->games_won.' - '.$stats->games_lost,
            'matches' => (int) $stats->match_count,
            'perGameWinrates' => $perGame,
            'otpWinrate' => Winrate::percentage($playDraw['otp_wins'], $playDraw['otp_losses']),
            'otpRecord' => $playDraw['otp_wins'].' - '.$playDraw['otp_losses'],
            'otdWinrate' => Winrate::percentage($playDraw['otd_wins'], $playDraw['otd_losses']),
            'otdRecord' => $playDraw['otd_wins'].' - '.$playDraw['otd_losses'],
            'avgTurns' => $gameStats->avg_turns !== null ? round((float) $gameStats->avg_turns, 1) : null,
            'avgMulligans' => $gameStats->avg_mulligans !== null ? round((float) $gameStats->avg_mulligans, 1) : null,
            'onPlayRate' => Winrate::percentage((int) $gameStats->on_play_count, (int) $gameStats->on_draw_count),
            'bestCards' => [],
            'worstCards' => [],
            'matchHistory' => $history,
        ];
    }

    /**
     * Get match IDs matching the deck, archetype, and date filters.
     */
    private static function getMatchIds(
        Deck $deck,
        Archetype $archetype,
        ?Carbon $from,
        ?Carbon $to,
        ?DeckVersion $deckVersion,
    ): Collection {
        $deckVersions = $deckVersion
            ? collect([$deckVersion->id])
            : $deck->versions()->pluck('id');

        $query = DB::table('matches as m')
            ->join('match_archetypes as ma', 'ma.mtgo_match_id', '=', 'm.id')
            ->whereIn('m.deck_version_id', $deckVersions->toArray())
            ->where('ma.archetype_id', $archetype->id)
            ->where('m.state', 'complete');

        if ($from && $to) {
            $query->whereBetween('m.started_at', [$from, $to]);
        }

        return $query
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereColumn('g.match_id', 'm.id')
                    ->whereColumn('gp.player_id', 'ma.player_id')
                    ->where('gp.is_local', 0);
            })
            ->distinct()
            ->pluck('m.id');
    }

    /**
     * Compute basic match and game win/loss counts.
     */
    private static function computeStats(array $matchIds): object
    {
        return DB::table('matches as m')
            ->whereIn('m.id', $matchIds)
            ->selectRaw("
                COUNT(DISTINCT m.id) as match_count,
                COUNT(DISTINCT CASE WHEN m.outcome = 'win' THEN m.id END) as match_wins,
                COUNT(DISTINCT CASE WHEN m.outcome = 'loss' THEN m.id END) as match_losses,
                SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 1)) as games_won,
                SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 0)) as games_lost
            ")
            ->first();
    }

    /**
     * Compute on-the-play and on-the-draw game win/loss stats.
     */
    private static function computePlayDrawStats(array $matchIds): array
    {
        $rows = DB::table('games as g')
            ->join('game_player as gp', 'gp.game_id', '=', 'g.id')
            ->whereIn('g.match_id', $matchIds)
            ->where('gp.is_local', true)
            ->whereNotNull('g.won')
            ->groupBy('gp.on_play')
            ->selectRaw('
                gp.on_play,
                SUM(CASE WHEN g.won = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN g.won = 0 THEN 1 ELSE 0 END) as losses
            ')
            ->get()
            ->keyBy('on_play');

        $otp = $rows->get(1);
        $otd = $rows->get(0);

        return [
            'otp_wins' => (int) ($otp->wins ?? 0),
            'otp_losses' => (int) ($otp->losses ?? 0),
            'otd_wins' => (int) ($otd->wins ?? 0),
            'otd_losses' => (int) ($otd->losses ?? 0),
        ];
    }

    /**
     * Compute average turn count, average mulligan count, and on-play rate.
     */
    private static function computeGameStats(array $matchIds): object
    {
        return DB::table('games as g')
            ->join('game_player as gp', 'gp.game_id', '=', 'g.id')
            ->whereIn('g.match_id', $matchIds)
            ->where('gp.is_local', true)
            ->selectRaw('
                AVG(g.turn_count) as avg_turns,
                AVG(gp.mulligan_count) as avg_mulligans,
                SUM(CASE WHEN gp.on_play = 1 THEN 1 ELSE 0 END) as on_play_count,
                SUM(CASE WHEN gp.on_play = 0 THEN 1 ELSE 0 END) as on_draw_count
            ')
            ->first();
    }

    /**
     * Get match history in descending date order.
     */
    private static function getMatchHistory(array $matchIds): array
    {
        return MtgoMatch::query()
            ->whereIn('id', $matchIds)
            ->with([
                'games' => fn ($q) => $q->orderBy('started_at'),
                'games.players',
            ])
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (MtgoMatch $match) => [
                'id' => $match->id,
                'date' => $match->started_at->setTimezone(AppSetting::displayTimezone())->toISOString(),
                'dateFormatted' => $match->started_at->setTimezone(AppSetting::displayTimezone())->format('M j'),
                'isLeague' => $match->league_id !== null,
                'leagueName' => null,
                'opponentName' => $match->games->first()?->players->first(fn ($p) => ! $p->pivot->is_local)?->username,
                'score' => $match->gamesWon().'-'.$match->gamesLost(),
                'outcome' => $match->outcome?->value,
                'gameResults' => $match->games->map(fn ($g) => $g->won)->all(),
            ])
            ->all();
    }

    /**
     * Compute win rates per game number (Game 1 = pre-board, Game 2/3 = post-board).
     *
     * @return array<int, array{gameNumber: int, winrate: int, record: string, wins: int, losses: int}>
     */
    private static function computePerGameWinrates(array $matchIds): array
    {
        // Use ROW_NUMBER to assign game position within each match
        $rows = DB::select('
            SELECT
                game_num,
                SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN won = 0 THEN 1 ELSE 0 END) as losses
            FROM (
                SELECT
                    g.won,
                    ROW_NUMBER() OVER (PARTITION BY g.match_id ORDER BY g.started_at) as game_num
                FROM games g
                WHERE g.match_id IN ('.implode(',', $matchIds).')
                AND g.won IS NOT NULL
            )
            GROUP BY game_num
            ORDER BY game_num
        ');

        return collect($rows)
            ->filter(fn ($row) => $row->game_num <= 3)
            ->map(fn ($row) => [
                'gameNumber' => (int) $row->game_num,
                'winrate' => Winrate::percentage((int) $row->wins, (int) $row->losses),
                'record' => $row->wins.' - '.$row->losses,
                'wins' => (int) $row->wins,
                'losses' => (int) $row->losses,
            ])
            ->values()
            ->all();
    }

    /**
     * Return an empty result set when no matches are found.
     */
    private static function emptyResult(): array
    {
        return [
            'matchWinrate' => 0,
            'gameWinrate' => 0,
            'matchRecord' => '0 - 0',
            'gameRecord' => '0 - 0',
            'matches' => 0,
            'perGameWinrates' => [],
            'otpWinrate' => 0,
            'otpRecord' => '0 - 0',
            'otdWinrate' => 0,
            'otdRecord' => '0 - 0',
            'avgTurns' => null,
            'avgMulligans' => null,
            'onPlayRate' => 0,
            'bestCards' => [],
            'worstCards' => [],
            'matchHistory' => [],
        ];
    }
}
