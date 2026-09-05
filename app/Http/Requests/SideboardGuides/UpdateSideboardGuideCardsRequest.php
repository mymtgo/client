<?php

namespace App\Http\Requests\SideboardGuides;

use App\Actions\SideboardGuides\GetVersionZoneQuantities;
use App\Enums\SideboardDirection;
use App\Models\SideboardGuide;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSideboardGuideCardsRequest extends FormRequest
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
        return [
            'cards' => ['present', 'array'],
            'cards.*.oracle_id' => ['required', 'string', 'max:64'],
            'cards.*.direction' => ['required', Rule::enum(SideboardDirection::class)],
            'cards.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * Quantity ceilings come from the deck's current version: you cannot bring
     * in more copies than the sideboard holds, nor cut more than the maindeck
     * runs. A card the version no longer contains is only accepted if the guide
     * already lists it (a stale entry the player has not yet removed), so a
     * plan can be re-saved without first cleaning up, but nothing new can be
     * planned for a card that is not in the deck.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var SideboardGuide $guide */
                $guide = $this->route('sideboardGuide');
                $version = $guide->deck->latestVersion;
                $zones = $version ? GetVersionZoneQuantities::run($version) : ['in' => [], 'out' => []];

                $existing = $guide->cards
                    ->mapWithKeys(fn ($card) => [$card->direction->value.':'.$card->oracle_id => $card->quantity])
                    ->all();

                $seen = [];

                foreach ($this->input('cards', []) as $index => $card) {
                    if (! is_array($card)) {
                        continue;
                    }

                    $direction = $card['direction'] ?? null;
                    $oracleId = $card['oracle_id'] ?? null;
                    $quantity = (int) ($card['quantity'] ?? 0);

                    if (! is_string($direction) || ! is_string($oracleId) || ! isset($zones[$direction])) {
                        continue;
                    }

                    $key = $direction.':'.$oracleId;

                    if (isset($seen[$key])) {
                        $validator->errors()->add("cards.$index.oracle_id", 'This card is listed twice for the same direction.');

                        continue;
                    }

                    $seen[$key] = true;

                    $ceiling = $zones[$direction][$oracleId] ?? null;

                    if ($ceiling === null) {
                        $saved = $existing[$direction.':'.$oracleId] ?? null;

                        if ($saved === null || $saved !== $quantity) {
                            $validator->errors()->add("cards.$index.oracle_id", 'This card is not in that part of the current decklist.');
                        }

                        continue;
                    }

                    if ($quantity > $ceiling) {
                        $noun = $direction === 'in' ? 'sideboard' : 'maindeck';
                        $validator->errors()->add("cards.$index.quantity", "Only $ceiling in the $noun.");
                    }
                }
            },
        ];
    }

    /**
     * @return array<int, array{oracle_id: string, direction: string, quantity: int}>
     */
    public function cards(): array
    {
        return array_values(array_map(fn (array $card) => [
            'oracle_id' => $card['oracle_id'],
            'direction' => $card['direction'],
            'quantity' => (int) $card['quantity'],
        ], $this->validated('cards', [])));
    }
}
