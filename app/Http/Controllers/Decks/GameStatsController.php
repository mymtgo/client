<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\AggregateGameStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GameStatsController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck): Response
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $opponent = $request->input('opponent') ?: null;
        $hidePhantom = AppSettings::hidePhantomLeagues();

        $rows = AggregateGameStats::run($deck, $timeframe, $opponent, $hidePhantom);
        $opponents = $this->opponentOptions($deck);

        return Inertia::render('decks/GameStats', [
            ...$shared,
            'currentPage' => 'game-stats',
            'timeframe' => $timeframe,
            'opponent' => $opponent,
            'stats' => [
                'rows' => $rows,
                'opponents' => $opponents,
            ],
        ]);
    }

    /**
     * Distinct opponent archetypes this deck has faced in at least one completed match.
     *
     * @return array<int, array{uuid: string, name: string, color_identity: ?string}>
     */
    protected function opponentOptions(Deck $deck): array
    {
        $deckVersionIds = $deck->versions()->pluck('id')->all();

        if (empty($deckVersionIds)) {
            return [];
        }

        return DB::table('archetypes as a')
            ->join('match_archetypes as ma', 'ma.archetype_id', '=', 'a.id')
            ->join('matches as m', 'm.id', '=', 'ma.mtgo_match_id')
            ->whereIn('m.deck_version_id', $deckVersionIds)
            ->where('m.state', 'complete')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereColumn('g.match_id', 'm.id')
                    ->whereColumn('gp.player_id', 'ma.player_id')
                    ->where('gp.is_local', false);
            })
            ->distinct()
            ->orderBy('a.name')
            ->get(['a.uuid', 'a.name', 'a.color_identity'])
            ->map(fn ($r) => [
                'uuid' => (string) $r->uuid,
                'name' => (string) $r->name,
                'color_identity' => $r->color_identity,
            ])
            ->all();
    }
}
