<?php

namespace App\Data\Front;

use App\Models\Card;
use Spatie\LaravelData\Data;

/** @typescript  */
class CardData extends Data
{
    public function __construct(
        public ?string $name,
        public ?string $type,
        public ?string $identity,
        public ?string $image,
        public int $quantity = 1,
        public bool $sideboard = false
    ) {}

    public static function fromModel(Card $card): self
    {
        $type = $card->type;

        if ($type == 'Basic Land') {
            $type = 'Land';
        }

        return new self(
            name: $card->name,
            type: $type,
            identity: $card->color_identity,
            image: $card->image,
            quantity: $card->quantity ?: 1,
            sideboard: $card->sideboard,
        );
    }
}
