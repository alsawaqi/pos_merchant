<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Offers;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Support\MerchantTenantContext;
use App\Support\OfferConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * P-F9 — validates POST /api/offers.
 *
 * Shared fields follow CreateDiscountRequest exactly; the type-specific
 * `config` is validated STRICTLY per type by {@see OfferConfig} (shape +
 * tenant ownership of every referenced product/category id) in an after
 * hook, surfacing under the `config` key for clean per-field errors.
 */
class CreateOfferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(OfferType::values())],
            'config' => ['required', 'array'],
            // Bundle is forced FALSE by the action whatever the client
            // sends; the other types default TRUE.
            'auto_apply' => ['sometimes', 'boolean'],
            'validity_start' => ['nullable', 'date'],
            'validity_end' => ['nullable', 'date', 'after:validity_start'],
            'dayofweek_mask' => ['nullable', 'integer', 'min:0', 'max:127'],
            // HH:MM:SS (24-hour); midnight wrap handled by the evaluator.
            'time_start' => ['nullable', 'string', 'regex:/^\\d{2}:\\d{2}:\\d{2}$/'],
            'time_end' => ['nullable', 'string', 'regex:/^\\d{2}:\\d{2}:\\d{2}$/'],
            'branch_scope_json' => ['nullable', 'array'],
            'branch_scope_json.*' => ['integer'],
            'max_per_order' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(OfferStatus::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->hasAny(['type', 'config'])) {
                return;
            }

            $type = OfferType::from((string) $this->input('type'));
            $companyId = app(MerchantTenantContext::class)->requiredId();

            foreach (OfferConfig::errors($type, (array) $this->input('config'), $companyId) as $error) {
                $v->errors()->add('config', $error);
            }
        });
    }
}
