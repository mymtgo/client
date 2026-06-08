<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
final class ExternalOpponentData extends Data
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
    ) {}
}
