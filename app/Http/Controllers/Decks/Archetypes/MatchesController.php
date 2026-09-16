<?php

namespace App\Http\Controllers\Decks\Archetypes;

use App\Actions\Matches\BuildMatchListProps;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;

class MatchesController extends ArchetypeTabController
{
    protected function component(): string
    {
        return 'decks/archetypes/Matches';
    }

    protected function tabProps(Request $request, Archetype $archetype, array $versionIds, string $timeframe): array
    {
        [$from, $to] = $this->getTimeRange($timeframe);

        $scoped = MtgoMatch::query()
            ->whereIn('deck_version_id', $versionIds)
            ->where('state', MatchState::Complete)
            ->whereBetween('started_at', [$from, $to]);

        return [
            ...BuildMatchListProps::run($scoped, $request, (string) $archetype->format, ['deck']),
            'pendingArchetypeCount' => MtgoMatch::query()
                ->whereIn('deck_version_id', $versionIds)
                ->whereNotNull('archetype_detection_queued_at')
                ->count(),
        ];
    }
}
