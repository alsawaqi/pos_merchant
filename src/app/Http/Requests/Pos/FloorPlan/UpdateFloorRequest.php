<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\FloorPlan;

use App\Enums\FloorStatus;
use App\Models\Floor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFloorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:64'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:64'],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
            'status' => ['sometimes', 'string', Rule::in(FloorStatus::values())],
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
            /** @var Floor|null $current */
            $current = $this->route('floor');
            if ($current === null) {
                return;
            }
            $taken = Floor::query()
                ->where('branch_id', $current->branch_id)
                ->where('name', $name)
                ->where('id', '!=', $current->id)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A floor with this name already exists in this branch.');
            }
        });
    }
}
