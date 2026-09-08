<?php

namespace App\Http\Controllers\Cards;

use App\Actions\Cards\SearchCards;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json(SearchCards::run($validated['q']));
    }
}
