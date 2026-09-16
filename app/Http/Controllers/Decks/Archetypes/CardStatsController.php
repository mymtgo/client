<?php

namespace App\Http\Controllers\Decks\Archetypes;

use App\Actions\Cards\GetCardGameStats;
use App\Actions\Cards\GetSideboardOracles;
use App\Actions\Decks\GetFilteredDeckWinrate;
use App\Facades\AppSettings;
use App\Models\Archetype;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardStatsController extends ArchetypeTabController
{
    protected function component(): string
    {
        return 'decks/archetypes/CardStats';
    }

    protected function tabProps(Request $request, Archetype $archetype, array $versionIds, string $timeframe): array
    {
        $opponentArchetypeId = $request->filled('card_stats_archetype') ? (int) $request->input('card_stats_archetype') : null;
        $onPlay = $request->filled('card_stats_play_draw') ? $request->input('card_stats_play_draw') === 'play' : null;
        $isPostboard = $request->filled('card_stats_board') ? $request->input('card_stats_board') === 'postboard' : null;
        $opponent = $request->input('card_stats_perspective') === 'theirs';

        return [
            // Same shape as the per-deck payload so the card stats view is shared.
            'cardStats' => Inertia::defer(function () use ($versionIds, $opponentArchetypeId, $onPlay, $isPostboard, $opponent) {
                $sideboardOracles = $opponent ? collect() : GetSideboardOracles::run($versionIds);

                return [
                    'stats' => GetCardGameStats::forVersionIds($versionIds, $sideboardOracles, $opponentArchetypeId, $onPlay, $isPostboard, $opponent),
                    'archetypes' => GetCardGameStats::availableArchetypesForVersionIds($versionIds),
                    'perspective' => $opponent ? 'theirs' : 'mine',
                    'deckWinrate' => GetFilteredDeckWinrate::forVersionIds($versionIds, $opponentArchetypeId, $onPlay, $isPostboard),
                    'trust' => AppSettings::cardStatsTrust(),
                    'source' => 'local',
                    'refreshedAt' => null,
                    'externalError' => false,
                ];
            }),
        ];
    }
}
