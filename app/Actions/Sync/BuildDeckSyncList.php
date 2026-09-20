<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Actions\Limited\EnsureLimitedDeckVersion;
use App\Facades\AppSettings;
use App\Models\Deck;
use App\Services\Sync\DeckClientId;
use Carbon\Carbon;

/**
 * The rows behind the Account settings deck sync list: every constructed
 * deck, whether it holds a cloud slot, and the cooldown date of a slot it
 * has turned off but not yet released.
 *
 * Limited decks are left out. Their slot rules are supporter-only and their
 * identity is keyed differently, so they stay on the per-deck settings page.
 */
class BuildDeckSyncList
{
    /**
     * @return list<array{id: int, name: string, format: string, archetype: string|null, lastPlayedAtHuman: string|null, cloudSyncEnabled: bool, freesAt: string|null}>
     */
    public static function run(): array
    {
        $cooldowns = self::cooldowns();

        return Deck::forActiveAccount()
            ->withoutLimited()
            ->where('format', '!=', EnsureLimitedDeckVersion::FORMAT)
            ->with('archetype')
            ->withMax('matches', 'started_at')
            ->orderByDesc('cloud_sync_enabled')
            ->orderByDesc('matches_max_started_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Deck $deck) => [
                'id' => $deck->id,
                'name' => $deck->name,
                'format' => $deck->format,
                'archetype' => $deck->archetype?->name,
                'lastPlayedAtHuman' => $deck->matches_max_started_at
                    ? Carbon::parse($deck->matches_max_started_at)->toLocal()->diffForHumans()
                    : null,
                'cloudSyncEnabled' => (bool) $deck->cloud_sync_enabled,
                'freesAt' => $cooldowns[DeckClientId::for((string) $deck->mtgo_id)] ?? null,
            ])
            ->all();
    }

    /**
     * Slots the account has turned off but still holds, keyed by client id.
     *
     * @return array<string, string|null>
     */
    private static function cooldowns(): array
    {
        $slots = AppSettings::syncSlots();

        return collect($slots['decks'] ?? [])
            ->filter(fn (array $row) => ($row['disabled_at'] ?? null) !== null)
            ->mapWithKeys(fn (array $row) => [(string) $row['client_id'] => $row['frees_at'] ?? null])
            ->all();
    }
}
