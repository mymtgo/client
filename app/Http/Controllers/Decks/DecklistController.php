<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCards;
use App\Actions\Decks\BuildDecklist;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Data\Front\CardData;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DecklistController extends Controller
{
    public function __invoke(Deck $deck, Request $request)
    {
        $shared = GetDeckViewSharedProps::run($deck);

        // Respect ?version= query param, fall back to latest version
        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version')) ?? $deck->latestVersion
            : $deck->latestVersion;

        [$maindeck, $sideboard] = $deckVersion
            ? BuildDecklist::run($deckVersion)
            : [collect(), collect()];

        return Inertia::render('decks/Decklist', [
            ...$shared,
            'currentPage' => 'decklist',
            'maindeck' => $maindeck,
            'sideboard' => $sideboard,

            // Lazy: all version decklists for comparison
            'versionDecklists' => fn () => $this->buildVersionDecklists($deck),
        ]);
    }

    private function buildVersionDecklists(Deck $deck): array
    {
        $versions = $deck->versions()->orderBy('modified_at')->get();

        if ($versions->isEmpty()) {
            return [];
        }

        $allCardRefs = $versions->flatMap(fn ($v) => $v->cards)->toArray();
        $cardModels = GetCards::run($allCardRefs)->keyBy('oracle_id');

        $result = [];
        foreach ($versions as $version) {
            $deckCards = collect($version->cards)->map(function ($cardRef) use ($cardModels) {
                $template = $cardModels->get($cardRef['oracle_id']);

                if (! $template) {
                    return null;
                }

                $card = clone $template;
                $card->sideboard = $cardRef['sideboard'] === 'true';
                $card->quantity = (int) $cardRef['quantity'];

                return CardData::from($card);
            })->filter()->sortBy('type');

            $mainDeck = $deckCards->filter(fn ($c) => ! $c->sideboard)
                ->groupBy('type')
                ->sortBy(fn ($cards, $type) => match ($type) {
                    'Creature' => 1, 'Instant' => 2, 'Sorcery' => 3, 'Land' => 10, default => 5
                });

            $sideboard = $deckCards->filter(fn ($c) => (bool) $c->sideboard)->values();

            $result[(string) $version->id] = [
                'maindeck' => $mainDeck,
                'sideboard' => $sideboard,
            ];
        }

        return $result;
    }
}
