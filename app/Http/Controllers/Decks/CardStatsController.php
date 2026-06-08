<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\FetchExternalCardStats;
use App\Actions\Cards\GetCardGameStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Decks\GetFilteredDeckWinrate;
use App\Concerns\HasTimeframeFilter;
use App\Exceptions\ExternalCardStatsUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Settings\AppSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardStatsController extends Controller
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

        return Inertia::render('decks/CardStats', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'card-stats',
            'timeframe' => $timeframe,

            'cardStats' => function () use ($deck, $deckVersion, $request) {
                $opponentArchetypeId = $request->filled('card_stats_archetype')
                    ? (int) $request->input('card_stats_archetype')
                    : null;

                $onPlay = $request->filled('card_stats_play_draw')
                    ? $request->input('card_stats_play_draw') === 'play'
                    : null;

                $isPostboard = $request->filled('card_stats_board')
                    ? $request->input('card_stats_board') === 'postboard'
                    : null;

                $opponent = $request->input('card_stats_perspective') === 'theirs';
                $source = $request->input('card_stats_source') === 'external' ? 'external' : 'local';
                $perspective = $opponent ? 'theirs' : 'mine';
                $trust = app(AppSettings::class)->cardStatsTrust();

                if ($source === 'external' && $deck->archetype_id !== null && $deck->archetype !== null) {
                    try {
                        $external = FetchExternalCardStats::run(
                            archetype: $deck->archetype,
                            format: $deck->format,
                            opponentArchetypeId: $opponentArchetypeId,
                            onPlay: $onPlay,
                            isPostboard: $isPostboard,
                            perspective: $perspective,
                        );

                        return [
                            'stats' => $external->stats,
                            'archetypes' => $external->opponents,
                            'perspective' => $perspective,
                            'deckWinrate' => $external->archetypeWinrate,
                            'trust' => $trust,
                            'source' => 'external',
                            'refreshedAt' => $external->refreshedAt,
                            'externalError' => false,
                        ];
                    } catch (ExternalCardStatsUnavailable $e) {
                        report($e);
                        // fall through to local with error flag
                    }
                }

                return [
                    'stats' => GetCardGameStats::run($deck, $deckVersion, $opponentArchetypeId, $onPlay, $isPostboard, $opponent),
                    'archetypes' => GetCardGameStats::availableArchetypes($deck, $deckVersion),
                    'perspective' => $perspective,
                    'deckWinrate' => GetFilteredDeckWinrate::run($deck, $deckVersion, $opponentArchetypeId, $onPlay, $isPostboard),
                    'trust' => $trust,
                    'source' => 'local',
                    'refreshedAt' => null,
                    'externalError' => $source === 'external',
                ];
            },
        ]);
    }
}
