<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/customers/{customer:uuid}/points/adjust.
 *
 * points_delta is SIGNED integer (positive to credit, negative
 * to debit). Reason is mandatory (the Action enforces it too;
 * the form-request layer surfaces a cleaner field-level error).
 *
 * Cap of +/- 1,000,000 keeps a fat-finger entry from creating
 * a wildly off-the-books adjustment that would take a long
 * audit search to find.
 */
class AdjustPointBalanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'points_delta' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
