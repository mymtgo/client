<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class TriggerArchetypeDetectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter_archetype' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === 'none') {
                        return;
                    }

                    if (! ctype_digit((string) $value)) {
                        $fail('The archetype filter must be "none" or a numeric id.');
                    }
                },
            ],
        ];
    }
}
