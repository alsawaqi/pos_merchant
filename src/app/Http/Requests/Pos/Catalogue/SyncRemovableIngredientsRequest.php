<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Models\Ingredient;
use App\Models\Product;
use App\Support\Catalogue\RemovableIngredients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * LAUNCH review add-on — validates PUT /api/products/{product:uuid}/removable:
 * { lines: [{ ingredient_uuid, label?, label_ar? }] }, the full list of the
 * recipe lines ticked "Can be removed" ([] unticks them all). Only lines of
 * the product's saved recipe; the action re-checks inside its transaction.
 */
class SyncRemovableIngredientsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['lines' => ['present', 'array', 'max:50']] + RemovableIngredients::rules('lines');
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
        }];
    }
}
