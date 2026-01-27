<?php

namespace App\Actions;

use App\Models\Archetype;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class DetermineDeckArchetype
{
    public static function run(Collection $cards, string $format): ?array
    {
        $response = Http::withoutVerifying()->post('https://api.test/api/archetypes/estimate', [
            'format' => $format,
            'cards' => $cards,
        ]);

        $archetypes = $response->json();

        dd($cards, $format);
        if (! $response->ok() || ! count($archetypes)) {
            return null;
        }

        $archetype = array_first($archetypes);

        $archetypeModel = Archetype::where('uuid', $archetype['uuid'])->first();

        if (! $archetypeModel) {
            return null;
        }

        return [
            'archetype_id' => $archetypeModel->id,
            'confidence' => $archetype['confidence'],
        ];
    }
}
