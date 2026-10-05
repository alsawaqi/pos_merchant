<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\ItemBarcode;
use App\Support\Inventory\ContainerPresenter;
use App\Support\Inventory\Containers;
use App\Support\Inventory\ContainerUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One container of an ingredient (LAUNCH review add-on: token, display
 * names, what it holds, the count-container marker, the size lock and its
 * barcodes — {@see ContainerPresenter}).
 *
 * @mixin IngredientAltUnit
 */
class IngredientAltUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IngredientAltUnit $container */
        $container = $this->resource;
        /** @var Ingredient $ingredient */
        $ingredient = $container->relationLoaded('ingredient') && $container->ingredient !== null
            ? $container->ingredient
            : Ingredient::withTrashed()->findOrFail($container->ingredient_id);

        return ContainerPresenter::one(
            $container,
            $ingredient,
            Containers::of($ingredient),
            ContainerUsage::usedIds((int) $ingredient->id),
            ItemBarcode::query()->where('ingredient_id', $ingredient->id)->get(),
        );
    }
}
