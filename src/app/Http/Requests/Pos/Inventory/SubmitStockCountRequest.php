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
                if (! $hasPieces && ! $hasUnits) {
                    $v->errors()->add("lines.{$i}.counted_units", 'Each line needs a counted amount (pieces or units).');
                }
            }
        });
    }
}
