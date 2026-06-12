<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Deliveries;

use App\Enums\MerchantPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/deliveries/confirm (bulk: the statement matched).
 *
 * authorize() carries the permission so an unauthorized caller gets the
 * 403 BEFORE order_ids validation — a 422-first would leak which ids
 * exist (the PendingReconciliationDecisionRequest precedent).
 */
class ConfirmDeliveriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(MerchantPermission::DeliveriesManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer', 'min:1'],
        ];
    }
}
