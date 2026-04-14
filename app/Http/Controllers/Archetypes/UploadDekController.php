<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\ParseDekFile;
use App\Actions\Archetypes\ResolveCardsFromDek;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadDekController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'dek_file' => ['required', 'file', function ($attribute, $value, $fail) {
                if (! str_ends_with(strtolower($value->getClientOriginalName()), '.dek')) {
                    $fail('The file must be a .dek file.');
                }
            }],
        ]);

        $xml = $request->file('dek_file')->get();
        $parsedCards = ParseDekFile::run($xml);
        $result = ResolveCardsFromDek::run($parsedCards);

        return response()->json($result);
    }
}
