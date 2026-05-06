<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GenerateDeckDekFile;
use App\Models\Deck;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Native\Desktop\Dialog;

class ExportDekController
{
    public function __invoke(Deck $deck): JsonResponse
    {
        $xml = GenerateDeckDekFile::run($deck);
        $suggestedName = Str::slug($deck->name).'.dek';

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
