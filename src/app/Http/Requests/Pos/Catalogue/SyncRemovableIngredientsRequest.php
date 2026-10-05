<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\MerchantPermission;
use App\Models\Ingredient;
use App\Models\Product;
use App\Support\Catalogue\RemovableIngredients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * LAUNCH review add-on — validates PUT /api/products/{product:uuid}/removable:
 *
 *   { lines:    [{ ingredient_uuid, label?, label_ar? }],   the full list of
 *                recipe lines ticked "Can be removed" ([] unticks them all);
 *     expected: [{ ingredient_uuid, label, label_ar }] }    the ticks the page
 *                loaded with (fix order C-1, M1): the save is refused with
 *                409 when they are no longer the saved ones.
 *
 * Only lines of the product's saved recipe, each once, and no two lines with
 * the same final "NO …" name (fix order C-1, L2). The action re-checks inside
 * its transaction. Fix order C-1, L4 — catalogue.manage is checked first
 * (403), before any validation.
 */
class SyncRemovableIngredientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can(MerchantPermission::CatalogueManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['lines' => ['present', 'array', 'max:50']]
            + RemovableIngredients::rules('lines')
            + [
                'expected' => ['present', 'array', 'max:50'],
                'expected.*.ingredient_uuid' => ['required', 'string', 'uuid'],
                'expected.*.label' => ['nullable', 'string', 'max:100'],
                'expected.*.label_ar' => ['nullable', 'string', 'max:100'],
            ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $v): void {
            $product = $this->route('product');
            if (! $product instanceof Product) {
                return;
            }
            $recipe = Ingredient::query()
                ->withTrashed()
                ->where('company_id', $product->company_id)
                ->whereIn('id', $product->recipeLines()->pluck('ingredient_id')->all())
                ->pluck('uuid')
                ->map(fn ($uuid): string => (string) $uuid)
                ->all();
            RemovableIngredients::checkAgainstRecipe($v, 'lines', $this->input('lines'), $recipe);
            RemovableIngredients::checkNames($v, 'lines', $this->input('lines'), (int) $product->company_id);
        }];
    }
}
