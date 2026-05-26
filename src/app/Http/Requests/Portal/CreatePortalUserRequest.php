<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Enums\MerchantRole;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for POST /api/portal-users.
 *
 *   name         — display name
 *   email        — required, unique across pos_users platform-wide
 *                  (the table is shared with pos_admin so a
 *                  collision against a platform-admin row would
 *                  break login for either user)
 *   phone        — optional
 *   role         — one of the MerchantRole values
 *   branch_scope — null (= all branches) OR an array of branch
 *                  ids that the merchant actually owns
 *
 * The branch_scope ids are cross-checked against the actor's
 * own company's branches in {@see withValidator()} so a teammate
 * can never be granted access to another merchant's branch.
 */
class CreatePortalUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'email' => [
                'required', 'email:rfc', 'max:191',
                Rule::unique('pos_users', 'email'),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', 'string', Rule::in(MerchantRole::values())],
            'branch_scope' => ['nullable', 'array'],
            'branch_scope.*' => ['integer', 'min:1'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            $scope = $this->input('branch_scope');
            if (! is_array($scope) || $scope === []) {
                return;
            }

            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            // Every requested branch id must belong to the actor's
            // own company. Without this, an admin could grant a
            // teammate access to a branch owned by a different
            // merchant by hand-crafting the request.
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
