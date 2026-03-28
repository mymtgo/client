<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Data\Front\ArchetypeData;
use App\Data\Front\MatchData;
use App\Enums\MatchOutcome;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchesController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Deck $deck, Request $request)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        $stats = GetDeckStats::run($deck, $from, $to, $deckVersion);
        $allMatchIds = $stats['allMatchIds'];

        // Build filtered match query
        $query = $deck->matches()->select('matches.*')->where('state', 'complete')
            ->when($deckVersion, fn ($q) => $q->where('deck_version_id', $deckVersion->id));

        if ($filterFrom = $request->input('filter_from')) {
            $query->where('started_at', '>=', Carbon::parse($filterFrom)->startOfDay());
        }
        if ($filterTo = $request->input('filter_to')) {
            $query->where('started_at', '<=', Carbon::parse($filterTo)->endOfDay());
        }
        if ($result = $request->input('filter_result')) {
            if ($result === 'win') {
                $query->where('outcome', MatchOutcome::Win);
            } elseif ($result === 'loss') {
                $query->where('outcome', MatchOutcome::Loss);
            }
        }
        if ($type = $request->input('filter_type')) {
            if ($type === 'league') {
                $query->whereNotNull('league_id');
            } elseif ($type === 'casual') {
                $query->whereNull('league_id');
            }
        }
        if ($archetype = $request->input('filter_archetype')) {
            $query->whereHas('opponentArchetypes', fn ($q) => $q->where('archetype_id', $archetype));
        }

        $matches = MatchData::collect(
            $query->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])
                ->orderByDesc('started_at')
                ->paginate(50)
        );

        // Map internal format code to archetype format
        $formatMap = [
            'CMODERN' => 'modern',
            'CPAUPER' => 'pauper',
            'CLEGACY' => 'legacy',
            'CVINTAGE' => 'vintage',
            'CPREMODERN' => 'premodern',
        ];
        $archetypeFormat = $formatMap[$deck->format] ?? strtolower($deck->format);

        $archetypes = Archetype::query()
            ->where('format', $archetypeFormat)
            ->withCount(['matchArchetypes' => fn ($q) => $q->whereIn('mtgo_match_id', $allMatchIds)])
            ->orderByDesc('match_archetypes_count')
            ->orderBy('name')
            ->get()
            ->map(fn (Archetype $a) => [
                ...ArchetypeData::from($a)->toArray(),
                'matchCount' => $a->match_archetypes_count,
            ]);

        return Inertia::render('decks/Matches', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'matches',
            'timeframe' => $timeframe,
            'matches' => $matches,
            'archetypes' => $archetypes,
        ]);
    }
}
