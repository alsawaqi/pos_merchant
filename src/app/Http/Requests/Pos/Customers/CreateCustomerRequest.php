<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/customers.
 *
 * Name + phone are required. Plates is an optional array of
 * strings — the controller attaches them after the customer
 * is created (in the same DB transaction). Duplicate phone
 * + duplicate plate checks live in the Actions; surfacing
 * them via withValidator() would duplicate the rule.
 *
 * Phase D3 — optional CRM profile fields (§5.7.2):
 *   date_of_birth — canonical Y-m-d (what the HTML date input
 *     sends), never in the future.
 *   tags — flat array of short free-form strings (VIP,
 *     Blocked…). Case-insensitive dupes rejected up front;
 *     trim + final dedupe live in the Actions.
 */
class CreateCustomerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            // 32-char DB column matches the schema. Allow any
            // string content; the Action normalises (trim).
            'phone' => ['required', 'string', 'max:32'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:32', 'distinct:ignore_case'],
            // Optional initial plates array. Cap at 10 — far
            // more than realistic per customer; the (company_id,
            // customer_id, plate_number) link unique catches dupes.
            'plates' => ['nullable', 'array', 'max:10'],
            'plates.*' => ['required', 'string', 'max:32'],
        ];
    }
}
