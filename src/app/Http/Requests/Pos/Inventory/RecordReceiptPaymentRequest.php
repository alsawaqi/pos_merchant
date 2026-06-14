<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AP — validate one payment recorded against a credit purchase receipt.
 *
 * Shape only: the amount must be positive (the action clamps it to the
 * outstanding balance and rejects overpayment). method + note are optional free
 * labels; paid_at lets a back-dated payment be logged.
 */
class RecordReceiptPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Max matches the decimal(12,3) range of grand_total so a single
            // payment can settle any receipt (the action clamps to outstanding).
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
            'method' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
