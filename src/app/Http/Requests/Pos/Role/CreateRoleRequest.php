<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Role;

use App\Enums\MerchantPermission;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/roles.
 *
 *   name        — required, unique within the company team
 *                 (spatie's UNIQUE is on (team_id, name,
 *                 guard_name) so we cross-check at the
 *                 validator layer for a clean 422)
 *   description — optional
 *   permissions — optional array of permission keys; each must
 *                 be a known MerchantPermission value
 */
class CreateRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(MerchantPermission::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            $taken = \Spatie\Permission\Models\Role::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->where('team_id', $companyId)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A role with this name already exists.');
            }
        });
    }
}
