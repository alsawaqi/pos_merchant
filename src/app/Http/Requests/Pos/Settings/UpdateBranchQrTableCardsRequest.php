<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBranchQrTableCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'card_enabled' => ['required', 'string', Rule::in(['off', 'on'])],
            'geofence_mode' => ['required', 'string', Rule::in(['off', 'advisory', 'enforce'])],
        ];
    }
}
