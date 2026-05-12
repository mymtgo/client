<?php

namespace App\Http\Requests;

use App\Models\Archetype;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class MergeArchetypeRequest extends FormRequest
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
            'parent_id' => ['required', 'integer', 'exists:archetypes,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $source = $this->route('archetype');
                $parentId = $this->integer('parent_id');

                if (! $source instanceof Archetype) {
                    return;
                }

                if ($parentId === $source->id) {
                    $validator->errors()->add('parent_id', 'Cannot merge an archetype into itself.');

                    return;
                }

                $parent = Archetype::query()->find($parentId);

                if ($parent === null) {
                    return;
                }

                if ($source->format !== $parent->format) {
                    $validator->errors()->add('parent_id', 'Parent must share the same format.');
                }

                if ($source->is_fallback || $parent->is_fallback) {
                    $validator->errors()->add('parent_id', 'Fallback archetypes cannot be merged.');
                }

                if ($source->merged_into_id !== null) {
                    $validator->errors()->add('parent_id', 'This archetype is already merged.');
                }

                if ($parent->merged_into_id !== null) {
                    $validator->errors()->add('parent_id', 'Parent archetype is already merged.');
                }
            },
        ];
    }

    public function parent(): Archetype
    {
        return Archetype::query()->findOrFail($this->integer('parent_id'));
    }
}
