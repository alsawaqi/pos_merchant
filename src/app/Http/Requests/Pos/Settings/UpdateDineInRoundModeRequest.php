<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Permission and company-wide branch-scope gating live in the controller. */
class UpdateDineInRoundModeRequest extends FormRequest
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
            'mode' => ['required', 'string', Rule::in(['kitchen_direct', 'staff_confirm'])],
        ];
    }
}
