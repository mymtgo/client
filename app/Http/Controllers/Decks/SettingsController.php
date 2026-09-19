<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCards;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Data\Front\ArchetypeData;
use App\Data\Front\CardData;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Services\Sync\DeckClientId;
use App\Services\Sync\SyncTokens;
use App\Support\MtgoFormat;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __invoke(Deck $deck): Response
    {
        $shared = GetDeckViewSharedProps::run($deck);

        $deck->load('archetype');

        $archetypes = Archetype::where('format', MtgoFormat::key($deck->format))
            ->orderBy('name')
            ->withExists('decks')
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
            'cloudSync' => $this->cloudSync($deck),
        ]);
    }

    /**
     * @return array{linked: bool, enabled: bool, limit: int|null, used: int, freesAt: string|null, heldBy: string|null, requiresSupporter: bool}
     */
    private function cloudSync(Deck $deck): array
    {
        $slots = AppSettings::syncSlots();
        $clientId = DeckClientId::for((string) $deck->mtgo_id);

        $own = collect($slots['decks'] ?? [])->first(fn (array $row) => (string) $row['client_id'] === $clientId);
        $limit = $slots['limit'] ?? null;
        $used = (int) ($slots['used'] ?? 0);
        $full = $limit !== null && $used >= $limit;

        $heldBy = null;

        if (! $deck->cloud_sync_enabled && $own === null && $full) {
            $heldBy = Deck::query()->where('cloud_sync_enabled', true)->whereKeyNot($deck->id)->value('name');
        }

        return [
            'linked' => app(SyncTokens::class)->linked(),
            'enabled' => (bool) $deck->cloud_sync_enabled,
            'limit' => $limit,
            'used' => $used,
            'freesAt' => $own !== null && ($own['disabled_at'] ?? null) !== null ? $own['frees_at'] : null,
            'heldBy' => $heldBy,
            'requiresSupporter' => $deck->isLimited() && ! AppSettings::isSupporter(),
        ];
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
