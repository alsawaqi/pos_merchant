<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\FloorPlan;

use App\Enums\TableShape;
use App\Models\Table;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/floors/{floor:uuid}/tables.
 *
 * Min / max party invariants:
 *   - min_party (if set) must be ≥ 1
 *   - max_party (if set) must be ≥ min_party
 *   - max_party CAN be < seats (smaller party limit at a big
 *     table is legal: 10-seat round table that we cap to 6
 *     to avoid a tight squeeze)
 */
class CreateTableRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:32'],
            'seats' => ['nullable', 'integer', 'between:1,99'],
            'min_party' => ['nullable', 'integer', 'between:1,99'],
            'max_party' => ['nullable', 'integer', 'between:1,99'],
            'shape' => ['nullable', 'string', Rule::in(TableShape::values())],
            'notes' => ['nullable', 'string', 'max:500'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $floor = $this->route('floor');
            $label = trim((string) $this->input('label'));
            if ($floor !== null && $label !== '') {
                $taken = Table::query()
                    ->where('floor_id', $floor->id)
                    ->where('label', $label)
                    ->exists();
                if ($taken) {
                    $v->errors()->add('label', 'A table with this label already exists on this floor.');
                }
            }

            // min ≤ max constraint
            $min = $this->input('min_party');
            $max = $this->input('max_party');
            if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
                $v->errors()->add('max_party', 'Max party size must be greater than or equal to min party size.');
            }
        });
    }
}
