<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GenerateDekFile;
use App\Models\Archetype;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Native\Desktop\Dialog;

class ExportDekController
{
    public function __invoke(Archetype $archetype): JsonResponse
    {
        $xml = GenerateDekFile::run($archetype);
        $suggestedName = Str::slug($archetype->name).'.dek';

        $path = Dialog::new()
            ->title('Save Deck File')
            ->defaultPath($suggestedName)
            ->filter('MTGO Deck', ['dek'])
            ->save();

        if ($path) {
            File::put($path, $xml);

            return response()->json(['success' => true, 'path' => $path]);
        }

        return response()->json(['success' => false, 'cancelled' => true]);
    }
}
