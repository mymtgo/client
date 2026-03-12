<?php

namespace App\Http\Controllers\Debug\Decks;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Deck;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('debug/Decks', [
            'decks' => Deck::query()
                ->withTrashed()
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
            'accountOptions' => Account::query()
                ->orderBy('username')
                ->get(['id', 'username'])
                ->map(fn ($a) => [
                    'label' => $a->username,
                    'value' => (string) $a->id,
                ]),
        ]);
    }
}
