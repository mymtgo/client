<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Actions\Decks\GetDailyMatchResults;
use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Decks\GetPeerArchetypeChartData;
use App\Actions\Decks\GetStandoutCards;
use App\Actions\Leagues\GetLatestLeague;
use App\Actions\Leagues\GetLeagueResultDistribution;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
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
            'matchRecord' => $stats['matchRecord']->toData(),
            'gamesWon' => $stats['gamesWon'],
            'gamesLost' => $stats['gamesLost'],
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
            'peerChart' => Inertia::defer(
                fn () => GetPeerArchetypeChartData::run($deck, $from, $to),
            ),
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

        $results = GetDailyMatchResults::run($versionIds, $from, $to);

        if ($results->isEmpty()) {
            return [];
        }

        // Narrow chart range to actual data bounds to avoid generating thousands of empty days
        $firstDate = Carbon::parse($results->keys()->first())->startOfDay();
        $lastDate = Carbon::parse($results->keys()->last())->startOfDay();

        $carbonPeriod = CarbonPeriod::between($firstDate, $lastDate)->days();

        return collect($carbonPeriod)->map(function (Carbon $point) use ($results) {
            $key = $point->format('Y-m-d');
            $record = $results->get($key);

            return [
                'date' => $key,
                'wins' => $record ? $record->wins : 0,
                'losses' => $record ? $record->losses : 0,
                'draws' => $record ? $record->draws : 0,
                'winrate' => $record ? (string) $record->winrate() : null,
            ];
        })->toArray();
    }
}
