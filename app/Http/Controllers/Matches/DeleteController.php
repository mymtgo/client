<?php

namespace App\Http\Controllers\Matches;

use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;

class DeleteController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        MtgoMatch::destroy([$id]);

        return back();
    }
}
