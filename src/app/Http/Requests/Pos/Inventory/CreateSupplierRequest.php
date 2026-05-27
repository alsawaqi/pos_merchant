<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Models\Supplier;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateSupplierRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'contact' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }
            $taken = Supplier::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A supplier with this name already exists.');
            }
        });
    }
}
