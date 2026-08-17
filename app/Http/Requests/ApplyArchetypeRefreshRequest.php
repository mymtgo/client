<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyArchetypeRefreshRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'mappings' => ['sometimes', 'array'],
            'mappings.*' => ['nullable', 'integer'],
        ];
    }

    /**
     * Confirmed rename mappings, keyed by removed archetype id.
     *
     * @return array<int, int>
     */
    public function mappings(): array
    {
        return collect($this->validated('mappings', []))
            ->filter()
            ->map(fn ($successorId) => (int) $successorId)
            ->all();
    }
}
