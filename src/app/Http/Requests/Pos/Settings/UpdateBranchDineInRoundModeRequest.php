<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Permission gating lives in the controller; inherit maps to a deleted row. */
class UpdateBranchDineInRoundModeRequest extends FormRequest
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
            'mode' => ['required', 'string', Rule::in(['inherit', 'kitchen_direct', 'staff_confirm'])],
        ];
    }
}
