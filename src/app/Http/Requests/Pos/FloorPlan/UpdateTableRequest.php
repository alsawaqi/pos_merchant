<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\FloorPlan;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Models\Table;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTableRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:32'],
            'seats' => ['sometimes', 'integer', 'between:1,99'],
            'min_party' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'max_party' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'shape' => ['sometimes', 'string', Rule::in(TableShape::values())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'string', Rule::in(TableStatus::values())],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var Table|null $current */
            $current = $this->route('table');
            if ($current === null) {
                return;
            }

            if ($this->has('label')) {
                $label = trim((string) $this->input('label'));
                if ($label !== '') {
                    $taken = Table::query()
                        ->where('floor_id', $current->floor_id)
                        ->where('label', $label)
                        ->where('id', '!=', $current->id)
                        ->exists();
                    if ($taken) {
                        $v->errors()->add('label', 'A table with this label already exists on this floor.');
                    }
                }
            }

            // min ≤ max — use the effective values (newly
            // submitted OR current row value).
            $min = $this->has('min_party') ? $this->input('min_party') : $current->min_party;
            $max = $this->has('max_party') ? $this->input('max_party') : $current->max_party;
            if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
                $v->errors()->add('max_party', 'Max party size must be greater than or equal to min party size.');
            }
        });
    }
}
