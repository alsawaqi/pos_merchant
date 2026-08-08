<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

class ResolveLoyaltyShortfallReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
