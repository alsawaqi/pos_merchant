<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Models\Supplier;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSupplierRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:191'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('name')) {
                return;
            }
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }
            /** @var Supplier|null $current */
            $current = $this->route('supplier');
            $currentId = $current?->id ?? 0;
            $taken = Supplier::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->where('id', '!=', $currentId)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A supplier with this name already exists.');
            }
        });
    }
}
