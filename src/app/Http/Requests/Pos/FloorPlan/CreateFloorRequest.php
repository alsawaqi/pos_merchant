<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\FloorPlan;

use App\Models\Floor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/branches/{branch:uuid}/floors.
 *
 * Branch tenancy is enforced by the controller's
 * refuseIfNotInTenant — we only validate the field shape
 * here.
 */
class CreateFloorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'name_ar' => ['nullable', 'string', 'max:64'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $branch = $this->route('branch');
            if ($branch === null) {
                return;
            }
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }
            // (branch_id, name) UNIQUE — pre-empt the DB
            // violation with a clean 422.
            $taken = Floor::query()
                ->where('branch_id', $branch->id)
                ->where('name', $name)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A floor with this name already exists in this branch.');
            }
        });
    }
}
