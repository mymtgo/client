<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GenerateDekFile;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Native\Desktop\Dialog;

class ExportDekController
{
    public function __invoke(Archetype $archetype): RedirectResponse
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

            return redirect()->back()->with('success', 'Deck file saved.');
        }

        return redirect()->back();
    }
}
