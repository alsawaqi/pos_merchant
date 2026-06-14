<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PT — validate the purchase_tax_recoverable toggle. Permission is enforced in
 * the controller (catalogue.manage, same as the Taxes page it lives on).
 */
class UpdatePurchaseTaxRecoverableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_tax_recoverable' => ['required', 'boolean'],
        ];
    }
}
