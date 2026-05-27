<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Branch;

use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for
 *   PATCH /api/branches/{branch:uuid}
 *
 * Every field is `sometimes` — this is a partial update. Fields
 * not present are left untouched. Rules deliberately mirror
 * pos_admin's UpdateBranchRequest where the same column exists,
 * but lock down (omit) the merchant-immutable fields entirely:
 *
 *   - code, company_id, uuid          → identifiers, admin-only
 *   - country/region/district/city_id → admin's geo provisioning
 *
 * Status flip is allowed in payload only when the user holds the
 * `branches.transition_status` permission; the gate is enforced
 * inside UpdateMerchantBranchAction so a request that includes
 * `status` from a Manager fails at the action layer rather than
 * here (keeps the rule shape stable + lets us return a clear
 * 403-equivalent message).
 *
 * The `opening_hours_json` shape:
 *   {
 *     "mon": {"open":"09:00","close":"22:00","closed":false},
 *     "tue": {... },
 *     ...
 *   }
 * Each day key is optional; missing days = unspecified
 * (the POS device falls back to the company default).
 */
class UpdateMerchantBranchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hhmm = ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'manager_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:191'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],

            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            // Geofence in metres. Floor of 100 (smaller is
            // GPS-noise; staff would be marked "out of branch"
            // while standing at the counter) + ceiling of 2000
            // (above that the area is bigger than a city block,
            // makes attendance tracking meaningless). Matches
            // pos_admin StoreBranchRequest.
            'geofence_radius_m' => ['sometimes', 'integer', 'between:100,2000'],

            'opening_hours_json' => ['sometimes', 'nullable', 'array'],
            'opening_hours_json.*' => ['array'],
            'opening_hours_json.*.open' => ['sometimes', 'string', ...$hhmm],
            'opening_hours_json.*.close' => ['sometimes', 'string', ...$hhmm],
            'opening_hours_json.*.closed' => ['sometimes', 'boolean'],

            'default_order_type' => ['sometimes', 'string', Rule::in(BranchOrderType::values())],

            // status passes the validator; the
            // BranchesTransitionStatus permission gate lives in
            // the action layer so non-SuperAdmin attempts get
            // a clean 422-shaped error from there.
            'status' => ['sometimes', 'string', Rule::in(BranchStatus::values())],
        ];
    }
}
