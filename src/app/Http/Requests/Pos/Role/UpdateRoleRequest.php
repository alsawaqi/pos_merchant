<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Role;

use App\Enums\MerchantPermission;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

/**
 * Validates PATCH /api/roles/{role}.
 *
 * All fields `sometimes` — partial update. Name uniqueness is
 * re-checked excluding the current row so a no-op name resubmit
 * isn't flagged.
 */
class UpdateRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(MerchantPermission::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('name')) {
                return;
            }
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            /** @var Role|null $current */
            $current = $this->route('role');
            $currentId = $current?->id ?? 0;

            $taken = Role::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->where('team_id', $companyId)
                ->where('id', '!=', $currentId)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A role with this name already exists.');
            }
        });
    }
}
