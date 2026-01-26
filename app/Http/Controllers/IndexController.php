<?php

namespace App\Http\Controllers;

use App\Facades\Mtgo;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IndexController extends Controller
{
    public function __invoke(Request $request)
    {

        Mtgo::syncDecks(sync: true);

        return Inertia::render('Index', [

        ]);
    }
}
