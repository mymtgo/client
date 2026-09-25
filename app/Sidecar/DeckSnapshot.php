<?php

namespace App\Sidecar;

use Illuminate\Support\Collection;

final readonly class DeckSnapshot
{
    /**
     * @param  Collection<int, array{mtgo_id: int, quantity: int, sideboard: string}>|null  $items  signature rows; null when unreadable
     */
    public function __construct(
        public ?int $netDeckId,
        public ?Collection $items,
    ) {}

    /** Null for anything that is not an array: sidecar payloads are hostile input. */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        return new self(
            netDeckId: is_int($data['net_deck_id'] ?? null) ? $data['net_deck_id'] : null,
            items: self::items($data['items'] ?? null),
        );
    }

    /**
     * A half-parsed list would sign as a different deck, so one malformed
     * row discards the whole list.
     *
     * @return Collection<int, array{mtgo_id: int, quantity: int, sideboard: string}>|null
     */
    private static function items(mixed $items): ?Collection
    {
        if (! is_array($items) || $items === []) {
            return null;
        }

        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_int($item['catalog_id'] ?? null) || ! is_int($item['quantity'] ?? null) || ! is_bool($item['sideboard'] ?? null)) {
                return null;
            }

            $rows[] = [
                'mtgo_id' => $item['catalog_id'],
                'quantity' => $item['quantity'],
                'sideboard' => $item['sideboard'] ? 'true' : 'false',
            ];
        }

        return collect($rows);
    }
}
