<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;

class UpdateArchetypeMeta
{
    public static function run(
        Archetype $archetype,
        string $name,
        string $format,
        ?string $colorIdentity,
    ): void {
        $archetype->update([
            'name' => $name,
            'format' => strtolower($format),
            'color_identity' => $colorIdentity,
        ]);
    }
}
