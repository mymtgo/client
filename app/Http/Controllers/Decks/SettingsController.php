<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCards;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Data\Front\ArchetypeData;
use App\Data\Front\CardData;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __invoke(Deck $deck): Response
    {
        $shared = GetDeckViewSharedProps::run($deck);

        $deck->load('archetype');

        $formatMap = [
            'CMODERN' => 'modern',
            'CPAUPER' => 'pauper',
            'CLEGACY' => 'legacy',
            'CVINTAGE' => 'vintage',
            'CPREMODERN' => 'premodern',
        ];
        $archetypeFormat = $formatMap[$deck->format] ?? strtolower($deck->format);

        $archetypes = Archetype::where('format', $archetypeFormat)
            ->orderBy('name')
            ->get()
            ->map(fn (Archetype $a) => ArchetypeData::fromModel($a));

        $coverArtWithId = null;
        if ($deck->cover) {
            $coverArtWithId = [
                ...CardData::fromModel($deck->cover)->toArray(),
                'id' => $deck->cover->id,
            ];
        }

        $cardNames = $this->getCardNamesWithArt($deck);

        return Inertia::render('decks/Settings', [
            ...$shared,
            'currentPage' => 'settings',
            'coverArt' => $coverArtWithId,
            'cardNames' => $cardNames,
            'archetypes' => $archetypes,
        ]);
    }

    /**
     * Get unique card names from the deck's latest version that have
     * at least one card row with art_crop populated.
     *
     * @return string[]
     */
    private function getCardNamesWithArt(Deck $deck): array
    {
        $latestVersion = $deck->latestVersion;

        if (! $latestVersion) {
            return [];
        }

        $cards = GetCards::run($latestVersion->cards);

        $cardNames = $cards->pluck('name')->filter()->unique()->sort()->values();

        return Card::whereIn('name', $cardNames)
            ->whereNotNull('art_crop')
            ->where('art_crop', '!=', '')
            ->distinct()
            ->pluck('name')
            ->sort()
            ->values()
            ->toArray();
    }
}
