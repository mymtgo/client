<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\JsonResponse;

class DestroyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $count = MtgoMatch::where('imported', true)->count();

        MtgoMatch::where('imported', true)->each(fn (MtgoMatch $match) => $match->delete());

        return response()->json(['deleted' => $count]);
    }
}
