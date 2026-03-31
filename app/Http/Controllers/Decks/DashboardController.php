<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Decks\GetStandoutCards;
use App\Actions\Leagues\GetLatestLeague;
use App\Actions\Leagues\GetLeagueResultDistribution;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        $stats = GetDeckStats::run($deck, $from, $to, $deckVersion);

        return Inertia::render('decks/Dashboard', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'dashboard',
            'timeframe' => $timeframe,

            // KPI stats — eager
            'matchesWon' => $stats['wins'],
            'matchesLost' => $stats['losses'],
            'gamesWon' => $stats['gamesWon'],
            'gamesLost' => $stats['gamesLost'],
            'matchWinrate' => $stats['matchWinrate'],
            'gameWinrate' => $stats['gameWinrate'],
            'gamesOtp' => $stats['otpWon'] + $stats['otpLost'],
            'gamesOtpWon' => $stats['otpWon'],
            'gamesOtpLost' => $stats['otpLost'],
            'otpRate' => $stats['otpRate'],
            'gamesOtd' => $stats['otdWon'] + $stats['otdLost'],
            'gamesOtdWon' => $stats['otdWon'],
            'gamesOtdLost' => $stats['otdLost'],
            'otdRate' => $stats['otdRate'],

            // Lazy closure
            'chartData' => fn () => $this->buildDeckChartData($deck, $from, $to, $deckVersion),

            // Deferred
            'matchupSpread' => Inertia::defer(
                fn () => GetArchetypeMatchupSpread::run($deck, $from, $to, $deckVersion),
            ),
            'leagueResults' => Inertia::defer(
                fn () => GetLeagueResultDistribution::run($deck, $stats['allMatchIds']),
            ),
            'standoutCards' => Inertia::defer(
                fn () => GetStandoutCards::run($deck, $deckVersion),
            ),
            'latestLeague' => Inertia::defer(
                fn () => GetLatestLeague::run($deck, $stats['allMatchIds']),
            ),
        ]);
    }

    private function buildDeckChartData(Deck $deck, Carbon $from, Carbon $to, ?DeckVersion $deckVersion = null): array
    {
        $versionIds = $deckVersion
            ? collect([$deckVersion->id])
            : $deck->versions()->pluck('id');

        $results = MtgoMatch::complete()
            ->selectRaw("strftime('%Y-%m-%d', started_at) as period, SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->whereIn('deck_version_id', $versionIds)
            ->where('state', 'complete')
            ->whereBetween('started_at', [$from, $to])
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        if ($results->isEmpty()) {
            return [];
        }

        // Narrow chart range to actual data bounds to avoid generating thousands of empty days
        $firstDate = Carbon::parse($results->keys()->first())->startOfDay();
        $lastDate = Carbon::parse($results->keys()->last())->startOfDay();

        $carbonPeriod = CarbonPeriod::between($firstDate, $lastDate)->days();

        return collect($carbonPeriod)->map(function (Carbon $point) use ($results) {
            $key = $point->format('Y-m-d');
            $row = $results->get($key);

            return [
                'date' => $key,
                'wins' => $row ? (int) $row->wins : 0,
                'losses' => $row ? (int) ($row->total - $row->wins) : 0,
                'winrate' => $row ? (string) round($row->wins / $row->total * 100) : null,
            ];
        })->toArray();
    }
}
