<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class GameCardData extends Data
{
    public function __construct(
        public string $id,
        public string $mtgoId,
        public string $zone,
        public string $actualZone,
        public string $ownerId,
        public string $controllerId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['Id'],
            mtgoId: $data['CatalogID'],
            zone: $data['Zone'],
            actualZone: $data['ActualZone'],
            ownerId: $data['Owner'],
            controllerId: $data['Controller'],
        );
    }
}
