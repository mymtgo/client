<?php

namespace App\Http\Requests;

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReassignArchetypeVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'target_id' => ['required', 'integer', 'exists:archetypes,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $archetype = $this->route('archetype');
                $deck = $this->route('deck');
                $targetId = $this->integer('target_id');

                if (! $archetype instanceof Archetype || ! $deck instanceof ArchetypeDeck) {
                    return;
                }

                if ($deck->archetype_id !== $archetype->id) {
                    $validator->errors()->add('deck', 'Variant does not belong to this archetype.');

                    return;
                }

                if ($targetId === $archetype->id) {
                    $validator->errors()->add('target_id', 'Cannot reassign to the same archetype.');

                    return;
                }

                $target = Archetype::query()->find($targetId);
                if ($target === null) {
                    return;
                }

                if ($archetype->format !== $target->format) {
                    $validator->errors()->add('target_id', 'Target must share the same format.');
                }

                if ($target->is_fallback) {
                    $validator->errors()->add('target_id', 'Cannot reassign to a fallback archetype.');
                }

                if ($target->merged_into_id !== null) {
                    $validator->errors()->add('target_id', 'Target archetype is already merged.');
                }

                if ($archetype->merged_into_id !== null) {
                    $validator->errors()->add('target_id', 'Source archetype is already merged.');
                }
            },
        ];
    }

    public function target(): Archetype
    {
        return Archetype::query()->findOrFail($this->integer('target_id'));
    }
}
