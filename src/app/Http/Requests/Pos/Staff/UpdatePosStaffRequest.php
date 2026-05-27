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
 * PATCH /api/pos-staff/{posStaff}. All fields optional — partial
 * update. Branch + staff_code re-check the same tenancy /
 * uniqueness rules the create flow enforced.
 *
 * Unlike create, staff_code uniqueness excludes the row being
 * updated (a no-op staff_code re-submit shouldn't be flagged
 * as a duplicate of itself).
 */
class UpdatePosStaffRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'position' => ['sometimes', 'string', Rule::in(StaffPosition::values())],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'staff_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'hired_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            if ($this->has('branch_id')) {
                $branchId = (int) $this->input('branch_id');
                $ownsBranch = Branch::query()
                    ->where('id', $branchId)
                    ->where('company_id', $companyId)
                    ->exists();
                if (! $ownsBranch) {
                    $v->errors()->add('branch_id', 'The selected branch does not belong to your company.');
                }
            }

            if ($this->has('staff_code')) {
                $staffCode = $this->input('staff_code');
                if (is_string($staffCode) && $staffCode !== '') {
                    /** @var PosStaff|null $current */
                    $current = $this->route('posStaff');
                    $currentId = $current?->id ?? 0;

                    $taken = PosStaff::query()
                        ->where('company_id', $companyId)
                        ->where('staff_code', $staffCode)
                        ->where('id', '!=', $currentId)
                        ->exists();
                    if ($taken) {
                        $v->errors()->add('staff_code', 'This staff code is already in use at your company.');
                    }
                }
            }
        });
    }
}
