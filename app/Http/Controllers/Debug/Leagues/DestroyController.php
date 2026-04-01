<?php

namespace App\Http\Controllers\Debug\Leagues;

use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\RedirectResponse;

class DestroyController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        League::findOrFail($id)->delete();

        return back();
    }
}
