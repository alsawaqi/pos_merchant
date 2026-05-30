<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\SavedViews;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate updating a saved view. view_key is immutable (a preset belongs to
 * the screen it was created on); name / filters / is_default are mutable.
 */
class UpdateSavedViewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
