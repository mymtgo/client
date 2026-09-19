<?php

namespace App\Actions\Dashboard;

use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetRegistry;
use App\Dashboard\WidgetType;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;

/**
 * Turn the stored layout into something safe to render. Drops anything the
 * registry does not know, anything with a bad config shape, a repeat of a
 * single-instance type, and deck cards pointing at decks the active account
 * cannot see. Never writes back: the stored list stays as the user saved it.
 */
class ResolveDashboardLayout
{
    /**
     * @param  array<int, mixed>  $stored
     * @return array<int, array{id: string, type: string, config: array<string, mixed>, type_instance: WidgetType}>
     */
    public static function run(array $stored): array
    {
        $registry = app(WidgetRegistry::class);
        $seenSingles = [];
        $resolved = [];

        foreach ($stored as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '' || ! is_string($row['type'] ?? null)) {
                continue;
            }

            $type = $registry->find($row['type']);

            if ($type === null) {
                continue;
            }

            if (! $type->allowsMultiple()) {
                if (isset($seenSingles[$type->key()])) {
                    continue;
                }
                $seenSingles[$type->key()] = true;
            }

            try {
                $config = $type->validateConfig(is_array($row['config'] ?? null) ? $row['config'] : []);
            } catch (InvalidWidgetConfig) {
                continue;
            }

            if ($type->key() === 'deck_stats' && ! self::deckVisible((int) $config['deck_id'])) {
                continue;
            }

            if ($type->key() === 'archetype_stats' && ! Archetype::query()->whereKey((int) $config['archetype_id'])->exists()) {
                continue;
            }

            $resolved[] = [
                'id' => $row['id'],
                'type' => $type->key(),
                'config' => $config,
                'type_instance' => $type,
            ];
        }

        return $resolved;
    }

    private static function deckVisible(int $deckId): bool
    {
        return Deck::query()
            ->whereKey($deckId)
            ->when(Account::currentId(), fn ($q, $id) => $q->where('account_id', $id))
            ->exists();
    }
}
