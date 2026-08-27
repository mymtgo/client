<?php

namespace App\Data\Front;

use App\Models\Card;
use Spatie\LaravelData\Data;

/** @typescript */
class LimitedCardData extends Data
{
    public function __construct(
        public int $catalogId,
        public string $name,
        public bool $resolved,
        public ?string $type,
        public ?string $subType,
        public ?string $rarity,
        public string $colors,
        public ?string $manaCost,
        public ?float $cmc,
        public ?string $image,
        public ?string $artCrop,
        public ?string $oracleId,
    ) {}

    /**
     * Build from a catalog id and its resolved Card, if the catalog has one.
     * Unresolved ids still render, labelled with the raw id.
     */
    public static function fromCatalog(int $catalogId, ?Card $card): self
    {
        return new self(
            catalogId: $catalogId,
            name: $card?->name ?? "#{$catalogId}",
            resolved: $card?->name !== null,
            type: $card?->type,
            subType: $card?->sub_type,
            rarity: $card?->rarity,
            colors: (string) ($card?->colors ?? ''),
            manaCost: $card?->mana_cost,
            cmc: $card?->cmc !== null ? (float) $card->cmc : null,
            image: $card?->image_url,
            artCrop: $card?->art_crop_url,
            oracleId: $card?->oracle_id,
        );
    }
}
