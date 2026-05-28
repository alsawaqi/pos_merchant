<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/customers/{customer:uuid}/plates.
 *
 * One plate per call. Normalisation + duplicate check live in
 * AttachVehiclePlateAction. The 32-char cap matches the DB
 * column.
 */
class AttachVehiclePlateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plate_number' => ['required', 'string', 'max:32'],
        ];
    }
}
