<?php

namespace App\Actions\Decks;

use App\Actions\Util\TimeframeRange;
use App\Models\Deck;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AggregateGameStats
{
    /**
     * @return Collection<int, array{
     *   group: string,
     *   split: string,
     *   wins: int,
     *   losses: int,
     *   win_rate: float|null,
     *   mulligans: float|null,
     *   opponent_mulligans: float|null,
     *   turns: float|null,
     *   duration: int|null,
     *   clock_left: int|null,
     *   opponent_clock_left: int|null,
     *   clock_games: int,
     * }>
     */
    public static function run(
        Deck $deck,
        string $timeframe,
        ?string $opponentArchetypeUuid,
    ): Collection {
        $deckVersionIds = $deck->versions()->pluck('id')->all();

        if (empty($deckVersionIds)) {
            return self::emptyRows();
        }

        [$from, $to] = TimeframeRange::run($timeframe);

        $query = DB::table('games as g')
            ->join('matches as m', 'm.id', '=', 'g.match_id')
            ->leftJoin('game_player as gp_local', function ($j) {
                $j->on('gp_local.game_id', '=', 'g.id')->where('gp_local.is_local', true);
            })
            ->leftJoin('game_player as gp_opp', function ($j) {
                $j->on('gp_opp.game_id', '=', 'g.id')->where('gp_opp.is_local', false);
            })
            ->whereIn('m.deck_version_id', $deckVersionIds)
            ->where('m.state', 'complete')
            ->whereNotNull('g.won')
            ->whereBetween('m.started_at', [$from, $to]);

        if ($opponentArchetypeUuid !== null) {
            $query->whereExists(function ($q) use ($opponentArchetypeUuid) {
                $q->selectRaw('1')
                    ->from('match_archetypes as ma')
                    ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
                    ->whereColumn('ma.mtgo_match_id', 'm.id')
                    ->where('a.uuid', $opponentArchetypeUuid)
                    ->whereExists(function ($gp) {
                        $gp->selectRaw('1')
                            ->from('game_player as gp2')
                            ->join('games as g2', 'g2.id', '=', 'gp2.game_id')
                            ->whereColumn('g2.match_id', 'm.id')
                            ->whereColumn('gp2.player_id', 'ma.player_id')
                            ->where('gp2.is_local', false);
                    });
            });
        }

        $games = $query
            ->orderBy('g.match_id')
            ->orderBy('g.started_at')
            ->orderBy('g.id')
            ->get([
                'g.id',
                'g.match_id',
                'g.won',
                'g.turn_count',
                'g.started_at',
                'g.ended_at',
                'gp_local.on_play as on_play',
                'gp_local.mulligan_count as local_mulligans',
                'gp_opp.mulligan_count as opponent_mulligans',
                'gp_local.clock_remaining_ms_end as clock_left',
                'gp_opp.clock_remaining_ms_end as opponent_clock_left',
            ]);

        $numbered = $games
            ->groupBy('match_id')
            ->flatMap(fn ($matchGames) => $matchGames->values()->map(function ($g, $i) use ($matchGames) {
                $g->game_number = $i + 1;
                $g->is_final_game = $i === $matchGames->count() - 1;
                $g->duration = self::durationSeconds($g->started_at, $g->ended_at);

                return $g;
            }))
            ->values();

        return self::buildRows($numbered);
    }

    /**
     * @param  Collection<int, object>  $games
     * @return Collection<int, array<string, mixed>>
     */
    protected static function buildRows(Collection $games): Collection
    {
        $groups = [
            'all_games' => null,
            'game_1' => 1,
            'game_2' => 2,
            'game_3' => 3,
        ];
        $splits = [
            'overall' => null,
            'play' => true,
            'draw' => false,
        ];

        $rows = collect();

        foreach ($groups as $groupKey => $gameNumber) {
            foreach ($splits as $splitKey => $onPlay) {
                $scoped = $games->filter(function ($g) use ($gameNumber, $onPlay) {
                    if ($gameNumber !== null && (int) $g->game_number !== $gameNumber) {
                        return false;
                    }
                    if ($onPlay !== null && (bool) $g->on_play !== $onPlay) {
                        return false;
                    }

                    return true;
                });

                // MTGO's clock runs across the whole match, so the all games
                // rows read the clock off each match's final game: what was
                // left when the match ended.
                $clocked = ($gameNumber === null ? $scoped->where('is_final_game', true) : $scoped)
                    ->filter(fn ($g) => $g->clock_left !== null);

                $wins = $scoped->where('won', 1)->count();
                $losses = $scoped->where('won', 0)->count();
                $decided = $wins + $losses;

                $rows->push([
                    'group' => $groupKey,
                    'split' => $splitKey,
                    'wins' => $wins,
                    'losses' => $losses,
                    'win_rate' => $decided > 0 ? round($wins / $decided * 100, 1) : null,
                    'mulligans' => self::averageOrNull($scoped, 'local_mulligans'),
                    'opponent_mulligans' => self::averageOrNull($scoped, 'opponent_mulligans'),
                    'turns' => self::averageOrNull($scoped, 'turn_count'),
                    'duration' => self::averageWholeOrNull($scoped, 'duration'),
                    'clock_left' => self::averageWholeOrNull($clocked, 'clock_left'),
                    'opponent_clock_left' => self::averageWholeOrNull($clocked, 'opponent_clock_left'),
                    'clock_games' => $clocked->count(),
                ]);
            }
        }

        return $rows;
    }

    protected static function emptyRows(): Collection
    {
        return self::buildRows(collect());
    }

    /**
     * @param  Collection<int, object>  $games
     */
    protected static function averageOrNull(Collection $games, string $field): ?float
    {
        $values = $games->pluck($field)->filter(fn ($v) => $v !== null);

        if ($values->isEmpty()) {
            return null;
        }

        return round((float) $values->avg(), 2);
    }

    /**
     * How long a game took, in seconds. Null when either end is missing or
     * the timestamps run backwards: log timestamps are not trustworthy.
     */
    protected static function durationSeconds(?string $startedAt, ?string $endedAt): ?int
    {
        if ($startedAt === null || $endedAt === null) {
            return null;
        }

        $seconds = Carbon::parse($startedAt)->diffInSeconds(Carbon::parse($endedAt), false);

        return $seconds >= 0 ? (int) $seconds : null;
    }

    /**
     * An average rounded to a whole number, for clock milliseconds and game
     * seconds. Missing values are skipped rather than counted as zero: clock
     * data is sidecar-only, so most games have none.
     *
     * @param  Collection<int, object>  $games
     */
    protected static function averageWholeOrNull(Collection $games, string $field): ?int
    {
        $values = $games->pluck($field)->filter(fn ($v) => $v !== null);

        if ($values->isEmpty()) {
            return null;
        }

        return (int) round((float) $values->avg());
    }
}
