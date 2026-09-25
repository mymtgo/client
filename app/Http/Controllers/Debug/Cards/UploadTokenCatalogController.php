<?php

namespace App\Http\Controllers\Debug\Cards;

use App\Actions\Cards\PopulateTokensFromXml;
use App\Actions\Cards\ReresolveTokenPrintings;
use App\Http\Controllers\Controller;
use App\Services\Sync\SyncApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Upload this install's MTGO token catalog to the API, then re-resolve the
 * local tokens against it. The owner presses this once per MTGO release; the
 * API refuses anyone who is not an admin.
 */
class UploadTokenCatalogController extends Controller
{
    private const FILES = ['client_TOK', 'CARDNAME_STRING', 'COLOR', 'PRINTEDCARDSET_STRING'];

    public function __invoke(SyncApi $api): RedirectResponse
    {
        $directory = PopulateTokensFromXml::findCardDataSourceDir();
        $files = [];

        foreach (self::FILES as $name) {
            $path = $directory === null ? null : $directory.DIRECTORY_SEPARATOR.$name.'.xml';

            if ($path === null || ! is_readable($path)) {
                throw ValidationException::withMessages(['catalog' => "MTGO's {$name}.xml was not found. Is MTGO installed and has it run once?"]);
            }

            $files[$name] = (string) file_get_contents($path);
        }

        $result = $api->uploadTokenCatalog($files);
        $cleared = ReresolveTokenPrintings::run();

        return back()->with('status', "Mapped {$result['added']} new token ids ({$result['already_mapped']} already mapped). Re-resolving {$cleared} local tokens.");
    }
}
