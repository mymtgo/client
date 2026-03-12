<?php

namespace App\Http\Controllers\Debug\Decks;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateController extends Controller
{
    public function __invoke(Request $request, int $id): RedirectResponse
    {
        $deck = Deck::withTrashed()->findOrFail($id);

        $allowed = ['mtgo_id', 'name', 'format', 'account_id'];

        $field = collect($request->only($allowed))->keys()->first();

        if (! $field) {
            return back();
        }

        $rules = [
            'mtgo_id' => 'required|string',
            'name' => 'required|string',
            'format' => 'required|string',
            'account_id' => ['nullable', 'integer', Rule::exists(Account::class, 'id')],
        ];

        $request->validate([$field => $rules[$field]]);

        $deck->update([$field => $request->input($field)]);

        return back();
    }
}
