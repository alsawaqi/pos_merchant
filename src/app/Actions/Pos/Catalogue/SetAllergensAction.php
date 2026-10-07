<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalogue\Allergens;
use App\Support\Catalogue\AllergenSync;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH costs & allergens add-on (tester call 3) — the merchant's allergen
 * ticks:
 *
 *   ingredient(...)  on an ingredient or a prep item (what it contains)
 *   product(...)     on a product: 'contains' (a bought-in or physical item
 *                    has no recipe to work them out from) and 'may_contain'
 *                    (traces, by hand). What the product's recipe,
 *                    components or combo items bring is worked out on READ,
 *                    never stored here, and cannot be unticked. Fix order 1
 *                    (K-1): the merchant's own ticks are stored EXACTLY as
 *                    given (normalised codes), even when the recipe brings
 *                    the same allergen today — a recipe change must never
 *                    take a hand tick away. Reads show a "may contain" only
 *                    when it is not also contained.
 *
 * Full replace, one transaction. A change bumps every product and add-on
 * option above it so devices pull it ({@see AllergenSync::touch()}) and is
 * audited with the old and new ticks; no change writes nothing.
 */
final readonly class SetAllergensAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  list<string>  $allergens
     * @return list<string> the ticks now saved
     */
    public function ingredient(Ingredient $ingredient, array $allergens, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $ingredient->company_id !== $companyId) {
            abort(404);
        }
        $new = Allergens::normalise($allergens);

        return DB::transaction(function () use ($ingredient, $new, $actor, $companyId): array {
            $old = Allergens::normalise(DB::table('pos_ingredient_allergens')->where('ingredient_id', $ingredient->id)->lockForUpdate()->pluck('allergen'));
            if ($old === $new) {
                return $new;
            }
            DB::table('pos_ingredient_allergens')->where('ingredient_id', $ingredient->id)->delete();
            $now = now();
            DB::table('pos_ingredient_allergens')->insert(array_map(static fn (string $code): array => [
                'company_id' => $companyId, 'ingredient_id' => (int) $ingredient->id, 'allergen' => $code, 'created_at' => $now, 'updated_at' => $now,
            ], $new));
            // The ingredient row moves too (its own updated_at shows the change).
            DB::table('pos_ingredients')->where('id', $ingredient->id)->update(['updated_at' => $now]);
            AllergenSync::touch($companyId, ingredientIds: [(int) $ingredient->id]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: $ingredient->is_prep ? 'catalogue.prep_item.allergens_updated' : 'inventory.ingredient.allergens_updated',
                actorUserId: (int) $actor->getKey(),
                companyId: $companyId,
                auditableType: Ingredient::class,
                auditableId: (int) $ingredient->id,
                oldValues: ['allergens' => $old],
                newValues: ['allergens' => $new],
            ));

            return $new;
        });
    }

    /**
     * @param  list<string>  $contains
     * @param  list<string>|null  $mayContain  null = keep the saved "may contain" (K-12)
     * @return array{contains: list<string>, may_contain: list<string>} the ticks now saved
     */
    public function product(Product $product, array $contains, ?array $mayContain, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }
        // Fix order 1 (K-1) — the hand ticks as given; nothing is dropped.
        $wanted = ['contains' => Allergens::normalise($contains), 'may_contain' => $mayContain === null ? null : Allergens::normalise($mayContain)];

        return DB::transaction(function () use ($product, $wanted, $actor, $companyId): array {
            $rows = DB::table('pos_product_allergens')->where('product_id', $product->id)->lockForUpdate()->get(['allergen', 'kind']);
            $old = [
                'contains' => Allergens::normalise($rows->where('kind', Allergens::CONTAINS)->pluck('allergen')),
                'may_contain' => Allergens::normalise($rows->where('kind', Allergens::MAY_CONTAIN)->pluck('allergen')),
            ];
            $new = ['contains' => $wanted['contains'], 'may_contain' => $wanted['may_contain'] ?? $old['may_contain']];
            if ($old === $new) {
                return $new;
            }
            DB::table('pos_product_allergens')->where('product_id', $product->id)->delete();
            $now = now();
            $insert = [];
            foreach ($new as $kind => $codes) {
                foreach ($codes as $code) {
                    $insert[] = ['company_id' => $companyId, 'product_id' => (int) $product->id, 'allergen' => $code, 'kind' => $kind, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('pos_product_allergens')->insert($insert);
            AllergenSync::touch($companyId, productIds: [(int) $product->id]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.allergens_updated',
                actorUserId: (int) $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: (int) $product->id,
                oldValues: $old,
                newValues: $new,
            ));

            return $new;
        });
    }
}
