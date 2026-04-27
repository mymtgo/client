<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\ScanMatchOpponentCards;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\JsonResponse;

class ScanMatchController extends Controller
{
    public function __invoke(MtgoMatch $match): JsonResponse
    {
        $result = ScanMatchOpponentCards::run($match);

        if ($result === null) {
            return response()->json([
                'message' => 'No opponent cards found in this match.',
            ], 422);
        }

        return response()->json($result);
    }
}
