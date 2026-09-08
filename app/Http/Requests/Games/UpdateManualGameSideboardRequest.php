<?php

namespace App\Http\Requests\Games;

use App\Actions\Games\ResolveRegisteredDeckQuantities;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateManualGameSideboardRequest extends FormRequest
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
            'changes' => ['present', 'array'],
            'changes.*.mtgo_id' => ['required', 'integer'],
            'changes.*.quantity' => ['required', 'integer', 'min:1'],
            'changes.*.type' => ['required', 'in:in,out'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Game $game */
                $game = $this->route('game');

                $firstGameId = $game->match->games()->orderBy('started_at')->orderBy('id')->value('id');
                if ($firstGameId === $game->id) {
                    $validator->errors()->add('changes', 'Game 1 is played with the registered deck.');

                    return;
                }

                [$mains, $sideboard] = ResolveRegisteredDeckQuantities::run($game);
                $seen = [];

                foreach ($this->input('changes', []) as $change) {
                    $mtgoId = (int) $change['mtgo_id'];
                    $key = $change['type'].':'.$mtgoId;

                    if (isset($seen[$key])) {
                        $validator->errors()->add('changes', 'Each card can only be listed once per direction.');

                        return;
                    }
                    $seen[$key] = true;

                    $pool = $change['type'] === 'out' ? $mains : $sideboard;

                    if (! isset($pool[$mtgoId])) {
                        $validator->errors()->add('changes', $change['type'] === 'out'
                            ? 'Cards sided out must be in the registered maindeck.'
                            : 'Cards sided in must be in the registered sideboard.');

                        return;
                    }

                    if ((int) $change['quantity'] > $pool[$mtgoId]) {
                        $validator->errors()->add('changes', 'A change exceeds the registered number of copies.');

                        return;
                    }
                }
            },
        ];
    }
}
