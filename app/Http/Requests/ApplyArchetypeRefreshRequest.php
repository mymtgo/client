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
            'mappings.*' => ['nullable'],
        ];
    }

    /**
     * Confirmed rename mappings, keyed by removed archetype id. Values are a
     * local archetype id, or an API uuid string for an incoming archetype that
     * does not exist locally yet.
     *
     * @return array<int, int|string>
     */
    public function mappings(): array
    {
        return collect($this->validated('mappings', []))
            ->filter(fn ($successor) => $successor !== null && $successor !== '')
            ->map(fn ($successor) => is_numeric($successor) ? (int) $successor : (string) $successor)
            ->all();
    }
}
