<?php

namespace App\Http\Requests\Games;

use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateManualGameRevealsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && (bool) $game->match?->manual;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cards' => ['present', 'array', 'max:60'],
            'cards.*.mtgo_id' => ['required', 'integer', 'exists:cards,mtgo_id'],
            'cards.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $ids = collect($this->input('cards', []))->pluck('mtgo_id')->map(fn ($id) => (int) $id);

                if ($ids->count() !== $ids->unique()->count()) {
                    $validator->errors()->add('cards', 'Each card can only be listed once.');
                }
            },
        ];
    }
}
