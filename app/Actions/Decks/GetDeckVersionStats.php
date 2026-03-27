<?php

namespace App\Actions\Decks;

use App\Actions\Util\Winrate;
use App\Models\Deck;
use App\Models\Game;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetDeckVersionStats
{
    /**
     * Get per-version stats for a deck within a date range.
     * Returns an array with an "All versions" aggregate row first,
     * then one row per version with match/game/OTP/OTD stats.
     */
    public static function run(Deck $deck, Carbon $from, Carbon $to): array
    {
        $dateScope = fn ($q) => $q->whereBetween('started_at', [$from, $to]);

        $versions = $deck->versions()
            ->withCount([
                'matches' => $dateScope,
                'matches as won_matches_count' => fn ($q) => $dateScope($q)->where('outcome', 'win'),
                'matches as lost_matches_count' => fn ($q) => $dateScope($q)->where('outcome', 'loss'),
            ])
            ->orderBy('modified_at')
            ->get();

        // Compute game counts per version from the games table
        $versionGameCounts = DB::table('matches as m')
            ->join('games as g', 'g.match_id', '=', 'm.id')
            ->whereIn('m.deck_version_id', $versions->pluck('id'))
            ->where('m.state', 'complete')
            ->whereBetween('m.started_at', [$from, $to])
            ->whereNotNull('g.won')
            ->selectRaw('m.deck_version_id, SUM(CASE WHEN g.won = 1 THEN 1 ELSE 0 END) as games_won, SUM(CASE WHEN g.won = 0 THEN 1 ELSE 0 END) as games_lost')
            ->groupBy('m.deck_version_id')
            ->get()
            ->keyBy('deck_version_id');

        $latestVersionId = $versions->last()?->id;
        $versionIds = $versions->pluck('id');

        // Single batch query for OTP/OTD stats across all versions.
        // Uses DB::table to avoid Game model's boolean cast on 'won'
        // which would cast the SUM() aggregate to true/false.
        $otpStats = DB::table('games')
            ->join('game_player as gp', fn ($j) => $j->on('gp.game_id', '=', 'games.id')->where('gp.is_local', 1))
            ->join('matches as m', 'm.id', '=', 'games.match_id')
            ->whereIn('m.deck_version_id', $versionIds)
            ->where('m.state', 'complete')
            ->whereBetween('m.started_at', [$from, $to])
            ->selectRaw('m.deck_version_id, gp.on_play, SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as won, SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as lost')
            ->groupBy('m.deck_version_id', 'gp.on_play')
            ->get()
            ->groupBy('deck_version_id');

        // Compute aggregate across all versions
        $totalWins = $versions->sum('won_matches_count');
        $totalLosses = $versions->sum('lost_matches_count');
        $totalGamesWon = (int) $versionGameCounts->sum('games_won');
        $totalGamesLost = (int) $versionGameCounts->sum('games_lost');
        $allOtp = $otpStats->flatten(1)->where('on_play', 1);
        $allOtd = $otpStats->flatten(1)->where('on_play', 0);
        $aggOtpWon = (int) $allOtp->sum('won');
        $aggOtpLost = (int) $allOtp->sum('lost');
        $aggOtdWon = (int) $allOtd->sum('won');
        $aggOtdLost = (int) $allOtd->sum('lost');

        $result = [self::buildRow(
            id: null,
            label: 'All versions',
            isCurrent: false,
            dateLabel: null,
            wins: $totalWins,
            losses: $totalLosses,
            gamesWon: $totalGamesWon,
            gamesLost: $totalGamesLost,
            otpWon: $aggOtpWon,
            otpLost: $aggOtpLost,
            otdWon: $aggOtdWon,
            otdLost: $aggOtdLost,
        )];

        foreach ($versions as $i => $version) {
            $vOtp = $otpStats->get($version->id, collect())->first(fn ($r) => (int) $r->on_play === 1);
            $vOtd = $otpStats->get($version->id, collect())->first(fn ($r) => (int) $r->on_play === 0);

            $nextVersion = $versions[$i + 1] ?? null;
            $dateLabel = $version->modified_at->format('M d')
                .' - '
                .($nextVersion ? $nextVersion->modified_at->format('M d') : 'now');

            $result[] = self::buildRow(
                id: $version->id,
                label: 'v'.($i + 1),
                isCurrent: $version->id === $latestVersionId,
                dateLabel: $dateLabel,
                wins: (int) ($version->won_matches_count ?? 0),
                losses: (int) ($version->lost_matches_count ?? 0),
                gamesWon: (int) ($versionGameCounts->get($version->id)->games_won ?? 0),
                gamesLost: (int) ($versionGameCounts->get($version->id)->games_lost ?? 0),
                otpWon: (int) ($vOtp->won ?? 0),
                otpLost: (int) ($vOtp->lost ?? 0),
                otdWon: (int) ($vOtd->won ?? 0),
                otdLost: (int) ($vOtd->lost ?? 0),
            );
        }

        return $result;
    }

    private static function buildRow(
        ?int $id, string $label, bool $isCurrent, ?string $dateLabel,
        int $wins, int $losses, int $gamesWon, int $gamesLost,
        int $otpWon, int $otpLost, int $otdWon, int $otdLost,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'isCurrent' => $isCurrent,
            'dateLabel' => $dateLabel,
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => Winrate::percentage($wins, $losses),
            'gameWinrate' => Winrate::percentage($gamesWon, $gamesLost),
            'gamesOtpWon' => $otpWon,
            'gamesOtpLost' => $otpLost,
            'otpRate' => Winrate::percentage($otpWon, $otpLost),
            'gamesOtdWon' => $otdWon,
            'gamesOtdLost' => $otdLost,
            'otdRate' => Winrate::percentage($otdWon, $otdLost),
        ];
    }
}
