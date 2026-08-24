<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteDeckRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:DELETE'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmation.in' => 'Type DELETE to confirm.',
            'confirmation.required' => 'Type DELETE to confirm.',
        ];
    }
}
