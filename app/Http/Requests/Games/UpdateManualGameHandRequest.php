<?php

namespace App\Http\Requests\Games;

use App\Actions\Games\ResolveManualGameMaindeck;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateManualGameHandRequest extends FormRequest
{
    private const HAND_SIZE = 7;

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
            'mulligan_count' => ['required', 'integer', 'min:0', 'max:6'],
            'kept_hand' => ['present', 'array', 'max:7'],
            'kept_hand.*' => ['integer'],
            'bottomed' => ['sometimes', 'array', 'max:6'],
            'bottomed.*' => ['integer'],
            'mulliganed_hands' => ['sometimes', 'array', 'max:6'],
            'mulliganed_hands.*' => ['array', 'max:7'],
            'mulliganed_hands.*.*' => ['integer'],
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
                $game->loadMissing('players');

                $keptHand = array_map('intval', $this->input('kept_hand', []));
                $bottomed = array_map('intval', $this->input('bottomed', []));
                $mulliganedHands = array_map(fn ($hand) => array_map('intval', $hand), $this->input('mulliganed_hands', []));
                $mulligans = (int) $this->input('mulligan_count');

                if ($keptHand === [] && $mulligans === 0) {
                    return;
                }

                if (count($keptHand) + count($bottomed) !== self::HAND_SIZE) {
                    $validator->errors()->add('kept_hand', 'The final hand is seven cards: the ones you kept plus the ones you bottomed.');

                    return;
                }

                if (count($bottomed) !== $mulligans) {
                    $validator->errors()->add('bottomed', "After {$mulligans} mulligan(s) you bottom {$mulligans} card(s).");

                    return;
                }

                if (count($mulliganedHands) !== $mulligans) {
                    $validator->errors()->add('mulliganed_hands', 'Record one hand per mulligan, or leave a hand empty if you do not remember it.');

                    return;
                }

                $maindeck = ResolveManualGameMaindeck::run($game);

                if (! self::handFits([...$keptHand, ...$bottomed], $maindeck, $validator, 'kept_hand')) {
                    return;
                }

                foreach ($mulliganedHands as $index => $hand) {
                    if ($hand !== [] && count($hand) !== self::HAND_SIZE) {
                        $validator->errors()->add("mulliganed_hands.{$index}", 'A mulliganed hand is seven cards, or empty if you do not remember it.');

                        return;
                    }

                    if (! self::handFits($hand, $maindeck, $validator, "mulliganed_hands.{$index}")) {
                        return;
                    }
                }
            },
        ];
    }

    /**
     * @param  list<int>  $hand
     * @param  array<int, int>  $maindeck  mtgo id => copies
     */
    private static function handFits(array $hand, array $maindeck, Validator $validator, string $key): bool
    {
        foreach (array_count_values($hand) as $mtgoId => $count) {
            if (! isset($maindeck[$mtgoId])) {
                $validator->errors()->add($key, 'Every card in a hand must be in the maindeck for this game.');

                return false;
            }

            if ($count > $maindeck[$mtgoId]) {
                $validator->errors()->add($key, 'A card in a hand exceeds the number of copies in the maindeck.');

                return false;
            }
        }

        return true;
    }
}
