<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/branches/{branch:uuid}/stock-counts.
 *
 * Each line carries the physical count for one ingredient —
 * counted_pieces (converted via the ingredient's units_per_piece)
 * and/or counted_units (primary units directly). Zero IS a valid
 * count ("no bottles left on the shelf"), hence min:0 not gt:0.
 * Piece-config and fractional-piece rules live in
 * SubmitStockCountAction where the ingredient is in hand.
 */
class SubmitStockCountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.ingredient_uuid' => ['required', 'string', 'uuid', 'distinct'],
            'lines.*.counted_pieces' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'lines.*.counted_units' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            // LAUNCH item kind, A7 — counted_units may be counted in any unit
            // the ingredient knows (kg/l, a pack size, '@piece' = the count
            // container); null = the stored unit. Converted like every other
            // entry (IngredientUnitConverter).
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            // LAUNCH review add-on (D2) — one row per item may hold several
            // containers (3 × bottle 1.5 l + 5 × bottle 500 ml; 0 is a count);
            // the total fills in as Σ pieces × size and may only be LOWERED
            // (a part-used container). The breakdown is set to exactly these.
            'lines.*.containers' => ['sometimes', 'array', 'max:20'],
            'lines.*.containers.*.container_uuid' => ['required', 'string', 'max:64'],
            'lines.*.containers.*.pieces' => ['required', 'numeric', 'min:0', 'max:999999.9999'],
            'lines.*.containers.*.leaf_pieces' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $lines = $this->input('lines');
            if (! is_array($lines)) {
                return;
            }
            foreach ($lines as $i => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $hasPieces = isset($line['counted_pieces']) && $line['counted_pieces'] !== null && $line['counted_pieces'] !== '';
                $hasUnits = isset($line['counted_units']) && $line['counted_units'] !== null && $line['counted_units'] !== '';
                $hasContainers = isset($line['containers']) && is_array($line['containers']) && $line['containers'] !== [];
                if (! $hasPieces && ! $hasUnits && ! $hasContainers) {
                    $v->errors()->add("lines.{$i}.counted_units", 'Each line needs a counted amount (pieces or units).');
                }
            }
        });
    }
}
