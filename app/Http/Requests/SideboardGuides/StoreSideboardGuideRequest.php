<?php

namespace App\Http\Requests\SideboardGuides;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSideboardGuideRequest extends FormRequest
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
        /** @var Deck $deck */
        $deck = $this->route('deck');

        return [
            'archetype_id' => [
                'required',
                'integer',
                'exists:archetypes,id',
                Rule::unique('sideboard_guides', 'archetype_id')->where('deck_id', $deck->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archetype_id.unique' => 'A guide for this archetype already exists for this deck.',
        ];
    }
}
