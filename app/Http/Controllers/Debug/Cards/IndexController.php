<?php

namespace App\Http\Controllers\Debug\Cards;

use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = Card::query()->orderByDesc('id');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mtgo_id', 'like', "%{$search}%")
                    ->orWhere('oracle_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'missing' => $query->where(function ($q) {
                    $q->whereNull('name')
                        ->orWhereNull('scryfall_id')
                        ->orWhereNull('image');
                }),
                'complete' => $query->whereNotNull('name')
                    ->whereNotNull('scryfall_id')
                    ->whereNotNull('image'),
                'missing_art' => $query->whereNotNull('name')
                    ->where(function ($q) {
                        $q->whereNull('art_crop')
                            ->orWhere('art_crop', '');
                    }),
                default => null,
            };
        }

        $missingCount = Card::where(function ($q) {
            $q->whereNull('name')
                ->orWhereNull('scryfall_id')
                ->orWhereNull('image');
        })->count();

        $missingArtCount = Card::whereNotNull('name')
            ->where(function ($q) {
                $q->whereNull('art_crop')
                    ->orWhere('art_crop', '');
            })->count();

        return Inertia::render('debug/Cards', [
            'cards' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
            ],
            'missingCount' => $missingCount,
            'missingArtCount' => $missingArtCount,
            'totalCount' => Card::count(),
        ]);
    }
}
