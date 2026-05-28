<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/restock-requests/{request:uuid}/allocate.
 *
 * Allocations array is optional — omitting it means "send the
 * full requested amount of every line". When provided, it's a
 * map of line.id => allocated quantity (which can be 0 to skip
 * the line, or a value <= requested for partial). The Action
 * validates ownership of the line id + the cap of <= requested.
 *
 * Quantity can be 0 here (legitimate "skip this line"); the
 * action's >=0 check + the "no zero stock movement" rule both
 * handle the case downstream.
 */
class AllocateRestockRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Map line.id => allocated quantity. Optional.
            'allocations' => ['nullable', 'array'],
            // The keys are dynamic (line ids), so we validate
            // the values with a wildcard. Laravel's array
            // wildcard with `.` treats this as "any string key".
            'allocations.*' => ['required', 'numeric', 'gte:0', 'max:999999.999'],
        ];
    }
}
