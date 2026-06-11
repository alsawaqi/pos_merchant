<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Offers;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Offer;
use App\Support\MerchantTenantContext;
use App\Support\OfferConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * P-F9 — validates PATCH /api/offers/{offer}.
 *
 * Partial update. When `config` is present it is validated STRICTLY
 * against the EFFECTIVE type (payload type ?? the offer's current
 * type). Changing the type REQUIRES sending a config alongside — the
 * stored config of another type can never silently survive a re-type.
 */
class UpdateOfferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'string', Rule::in(OfferType::values())],
            // required_with is implicit — a type change without a config
            // fails even though the field is otherwise optional.
            'config' => ['required_with:type', 'array'],
            'auto_apply' => ['sometimes', 'boolean'],
            'validity_start' => ['nullable', 'date'],
            'validity_end' => ['nullable', 'date'],
            'dayofweek_mask' => ['nullable', 'integer', 'min:0', 'max:127'],
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
            if (! $this->has('config') || $v->errors()->hasAny(['type', 'config'])) {
                return;
            }

            /** @var Offer|null $offer */
            $offer = $this->route('offer');
            $type = $this->has('type')
                ? OfferType::from((string) $this->input('type'))
                : $offer?->type;
            if (! $type instanceof OfferType) {
                return;
            }

            $companyId = app(MerchantTenantContext::class)->requiredId();

            foreach (OfferConfig::errors($type, (array) $this->input('config'), $companyId) as $error) {
                $v->errors()->add('config', $error);
            }
        });
    }
}
