<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\DeliveryProviders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/delivery-providers.
 *
 * Name required (uniqueness checked in the Action). Color is
 * an optional 7-char hex (#RRGGBB) — pattern enforced here for
 * a clean 422 before the Action runs.
 */
class CreateDeliveryProviderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            // 7 chars including the leading hash.
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // P-G7 — the provider's cut; snapshotted onto orders at punch.
            'commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            // unsignedSmallInteger -> max 65535. The UI will use
            // gap-of-10 increments so this is far beyond use.
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
