<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/customers/{customer:uuid}/wallet/topup.
 *
 * Amount is positive-only (a "top-up" by a negative number is
 * an adjustment, semantically — use the adjust endpoint).
 *
 * Cap of 99,999.999 OMR fits the decimal(12,3) column with
 * room to spare.
 */
class TopUpWalletRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999.999'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
