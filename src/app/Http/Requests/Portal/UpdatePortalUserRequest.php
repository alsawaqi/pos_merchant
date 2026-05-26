<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Enums\MerchantRole;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/portal-users/{user} — partial update of
 * name / phone / role / branch_scope. Email + status are NOT
 * updatable here (status flips through dedicated suspend/
 * reactivate endpoints; email is the login identity).
 *
 * branch_scope ownership is re-checked the same way as on Create.
 */
class UpdatePortalUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', 'string', Rule::in(MerchantRole::values())],
            'branch_scope' => ['sometimes', 'nullable', 'array'],
            'branch_scope.*' => ['integer', 'min:1'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if (! $this->has('branch_scope')) {
                return;
            }
            $scope = $this->input('branch_scope');
            if (! is_array($scope) || $scope === []) {
                return;
            }

            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            $ownedCount = \App\Models\Branch::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $scope)
                ->count();

            if ($ownedCount !== count(array_unique($scope))) {
                $v->errors()->add('branch_scope', 'One or more selected branches do not belong to your company.');
            }
        });
    }
}
