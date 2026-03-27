<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchupsController extends Controller
{
    public function __invoke(Request $request, Deck $deck)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        return Inertia::render('decks/Matchups', [
            ...$shared,
            'currentPage' => 'matchups',
            'timeframe' => $timeframe,
            'matchupSpread' => GetArchetypeMatchupSpread::run($deck, $from, $to),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function getTimeRange(string $timeframe): array
    {
        $end = now()->endOfDay();

        $start = match ($timeframe) {
            'week' => now()->subDays(7)->startOfDay(),
            'biweekly' => now()->subWeeks(2)->startOfDay(),
            'monthly' => now()->subDays(30)->startOfDay(),
            'year' => now()->startOfYear()->startOfDay(),
            default => now()->startOfCentury()->startOfDay(),
        };

        return [$start, $end];
    }
}
