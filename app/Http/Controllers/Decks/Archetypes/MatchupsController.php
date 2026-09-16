<?php

namespace App\Http\Controllers\Decks\Archetypes;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Models\Archetype;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchupsController extends ArchetypeTabController
{
    protected function component(): string
    {
        return 'decks/archetypes/Matchups';
    }

    protected function tabProps(Request $request, Archetype $archetype, array $versionIds, string $timeframe): array
    {
        [$from, $to] = $this->getTimeRange($timeframe);

        return [
            'matchupSpread' => Inertia::defer(fn () => GetArchetypeMatchupSpread::forVersionIds($versionIds, $from, $to)),
        ];
    }
}
