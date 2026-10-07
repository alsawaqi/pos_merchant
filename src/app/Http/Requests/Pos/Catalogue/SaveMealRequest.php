<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\MerchantPermission;
use App\Models\Meal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Catalogue\ComboLinesInput;
use App\Support\Catalogue\MealMains;
use App\Support\Catalogue\MenuExtras;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * LAUNCH combo add-on (owner decision 4) — validates POST /api/meals and
 * PUT /api/meals/{meal:uuid}: the meal's name, its meal price (added to the
 * main's own price), the categories of its mains, the unticked mains, its
 * lines (as a combo's) and optional limited-time dates.
 *
 * The clash rule: an ACTIVE meal may not share a main with another active
 * meal ({@see MealMains::clashes()}); the save is refused (422 on
 * category_ids, naming each product and the other meal).
 */
class SaveMealRequest extends FormRequest
{
    /** A user without catalogue.manage gets 403 before any validation. */
    public function authorize(): bool
    {
        return $this->user()?->can(MerchantPermission::CatalogueManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'name_ar' => ['nullable', 'string', 'max:64'],
            'meal_price' => ['required', 'numeric', 'min:0', 'max:999.999', 'decimal:0,3'],
            'status' => ['sometimes', 'string', Rule::in([Meal::STATUS_ACTIVE, Meal::STATUS_INACTIVE])],
            'on_sale_from' => ['nullable', 'date_format:Y-m-d'],
            'on_sale_until' => ['nullable', 'date_format:Y-m-d'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'category_ids' => ['required', 'array', 'min:1', 'max:50'],
            'category_ids.*' => ['integer', 'min:1', 'distinct'],
            'excluded_product_uuids' => ['present', 'array', 'max:500'],
            'excluded_product_uuids.*' => ['string', 'uuid', 'distinct'],
            ...ComboLinesInput::rules('lines'),
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null || $v->errors()->isNotEmpty()) {
                return;
            }
            /** @var Meal|null $meal */
            $meal = $this->route('meal');
            MenuExtras::checkDates($v, $this->input('on_sale_from'), $this->input('on_sale_until'), 'on_sale_until');

            $categoryIds = array_map('intval', (array) $this->input('category_ids', []));
            $owned = ProductCategory::query()->where('company_id', $companyId)->whereIn('id', $categoryIds)->count();
            if ($owned !== count($categoryIds)) {
                $v->errors()->add('category_ids', 'A selected category does not belong to your company.');

                return;
            }
            $uuids = (array) $this->input('excluded_product_uuids', []);
            $excluded = Product::query()->where('company_id', $companyId)->whereIn('uuid', $uuids)
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if (count($excluded) !== count($uuids)) {
                $v->errors()->add('excluded_product_uuids', 'An unticked main does not belong to your company.');

                return;
            }
            if (MealMains::of($companyId, $categoryIds, $excluded) === []) {
                $v->errors()->add('category_ids', 'No main is left: tick at least one product of the chosen categories.');
            }
            ComboLinesInput::check($v, $companyId, $meal === null ? [] : ['meal_id' => (int) $meal->id], $this->input('lines'));

            $status = $this->input('status', $meal?->status ?? Meal::STATUS_ACTIVE);
            if ($status === Meal::STATUS_ACTIVE) {
                foreach (MealMains::clashes($companyId, $meal?->id !== null ? (int) $meal->id : null, $categoryIds, $excluded,
                    $this->input('on_sale_from'), $this->input('on_sale_until')) as $clash) {
                    $v->errors()->add('category_ids', MealMains::message($clash));
                }
            }
        }];
    }
}
