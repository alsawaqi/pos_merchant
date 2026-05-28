<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/customers/{customer:uuid}/wallet/adjust.
 *
 * SIGNED amount (positive to credit, negative to debit).
 * Reason mandatory.
 */
class AdjustWalletBalanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_delta' => ['required', 'numeric', 'not_in:0', 'min:-99999.999', 'max:99999.999'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
