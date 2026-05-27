<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Staff;

use App\Enums\StaffPosition;
use App\Models\Branch;
use App\Models\PosStaff;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the payload for POST /api/pos-staff.
 *
 *   name        — required, display name
 *   branch_id   — required, must belong to actor's company
 *   position    — required, one of StaffPosition values
 *   phone       — optional
 *   staff_code  — optional, max 64 chars; must be unique among
 *                 non-terminated staff at this company when set
 *   hired_at    — optional ISO date
 *
 * No PIN field — the server mints it.
 * No company_id field — auto-bound from MerchantTenantContext.
 */
class CreatePosStaffRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'position' => ['required', 'string', Rule::in(StaffPosition::values())],
            'phone' => ['nullable', 'string', 'max:32'],
            'staff_code' => ['nullable', 'string', 'max:64'],
            'hired_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            // Branch must belong to actor's company.
            $branchId = (int) $this->input('branch_id');
            if ($branchId > 0) {
                $ownsBranch = Branch::query()
                    ->where('id', $branchId)
                    ->where('company_id', $companyId)
                    ->exists();
                if (! $ownsBranch) {
                    $v->errors()->add('branch_id', 'The selected branch does not belong to your company.');
                }
            }

            // staff_code unique per company (active + suspended
            // only — terminated rows are soft-deleted and the
            // partial index already exempts them).
            $staffCode = $this->input('staff_code');
            if (is_string($staffCode) && $staffCode !== '') {
                $taken = PosStaff::query()
                    ->where('company_id', $companyId)
                    ->where('staff_code', $staffCode)
                    ->exists();
                if ($taken) {
                    $v->errors()->add('staff_code', 'This staff code is already in use at your company.');
                }
            }
        });
    }
}
