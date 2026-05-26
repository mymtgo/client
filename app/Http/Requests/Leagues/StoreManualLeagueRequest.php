<?php

namespace App\Http\Requests\Leagues;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualLeagueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $accountId = Account::currentId();

        return [
            'deck_id' => [
                'required',
                'integer',
                Rule::exists('decks', 'id')->where(fn ($q) => $q->where('account_id', $accountId)),
            ],
            'started_at' => ['required', 'date', 'before_or_equal:now'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
