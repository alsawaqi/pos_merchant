<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Catalogue\MenuExtras;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\RecipeEditGate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Partial-update a product. Mutable: every field except
 * uuid, company_id (tenant lock).
 *
 * category_id change re-validates ownership — moving a
 * product to a category from another company would be a
 * tenancy break.
 *
 * Audit event: catalogue.product.updated with old/new diffs.
 * Price changes specifically are flagged in the event (so
 * reporting can spot suspicious price drops).
 */
final readonly class UpdateProductAction
{
    private const MUTABLE_FIELDS = [
        'category_id',
        'sku',
        'barcode',
        'name',
        'name_ar',
        'description',
        'image_url',
        'base_price',
        // Phase 4.9 — per-product delivery override.
        'delivery_price',
        // Phase 7 — stock mode (the form always submitted it; it was
        // silently dropped here until Phase D2 fixed the omission).
        'stock_mode',
        // Phase D2 — unit-mode LOW STOCK badge threshold.
        'low_stock_threshold',
        // P-G1.5 — default shelf life in days (NULL = keeps indefinitely).
        'shelf_life_days',
        'cost_price',
        'tax_rate',
        // Phase D2 — §5.5.3 flags (tax_inclusive display-only for now).
        'tax_inclusive',
        'show_on_customer_tablet',
        // P-G2 — internal item (never on the POS menu or tablet).
        'is_internal',
        // PD3a — physical-item kind (only the physical-items endpoint
        // sends it; the catalogue requests don't validate it through).
        'internal_purpose',
        // G1 — menu time-window (both NULL = always available).
        'available_from',
        'available_until',
        'display_order',
        'status',
        // LAUNCH-P4 — Arabic description (L5) and the channels (B3). The
        // branch rule changes only through SyncProductBranchesAction; the
        // product type never changes after create.
        'description_ar',
        'sold_in_store',
        'sold_on_delivery',
        // LAUNCH review add-on — limited-time dates and the cooking time
        // (compared strictly: 0 minutes is not "not set").
        'on_sale_from',
        'on_sale_until',
        'cooking_minutes',
    ];

    /** LAUNCH review add-on — nullable fields where null and 0 / '' differ. */
    private const STRICT_FIELDS = ['on_sale_from', 'on_sale_until', 'cooking_minutes'];

    /** The stock modes whose products carry a recipe (consumed at sale / at production). */
    private const RECIPE_MODES = ['ingredient', 'cooked'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private UpdateProductRecipeAction $updateRecipe,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Product $product, array $attributes, User $actor): Product
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        // LAUNCH-P4 B2 — a combo keeps no stock of its own: the items chosen
        // in it are counted when it sells.
        if ($product->isCombo() && array_key_exists('stock_mode', $attributes)
            && $attributes['stock_mode'] !== null && $attributes['stock_mode'] !== 'untracked') {
            throw new RuntimeException('A combo has no stock of its own: the items in it are counted when it sells.');
        }

        // Category move — verify the new category is ours.
        if (array_key_exists('category_id', $attributes) && ! empty($attributes['category_id'])) {
            $categoryOwned = ProductCategory::query()
                ->where('id', $attributes['category_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (! $categoryOwned) {
                throw new RuntimeException(
                    'The selected category does not belong to your company.',
                );
            }
        }

        // Fix order 1, L3 — a stock-mode change into or out of a recipe type
        // (made-to-order / cooked) on a product WITH recipe lines switches
        // recipe deduction on, off or between sale and production: it needs
        // "Edit recipes" (+ catalogue.view), like any recipe change.
        $oldMode = $product->stock_mode instanceof \BackedEnum ? $product->stock_mode->value : (string) $product->stock_mode;
        $newMode = array_key_exists('stock_mode', $attributes) && $attributes['stock_mode'] !== null
            ? (string) $attributes['stock_mode']
            : $oldMode;
        $modeChanges = $newMode !== $oldMode;
        $hasRecipe = $modeChanges && $product->recipeLines()->exists();
        if ($hasRecipe && (in_array($oldMode, self::RECIPE_MODES, true) || in_array($newMode, self::RECIPE_MODES, true))) {
            RecipeEditGate::ensure($actor);
        }

        return DB::transaction(function () use ($product, $attributes, $actor, $companyId, $oldMode, $newMode, $hasRecipe): Product {
            $changes = [];

            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $product->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum
                    ? $oldValue->value
                    : $oldValue;

                // Money columns come back as strings via the
                // decimal cast — normalize both sides for the
                // comparison.
                if (in_array($field, ['base_price', 'delivery_price', 'low_stock_threshold', 'cost_price', 'tax_rate'], true)) {
                    $sameValue = (string) $oldComparable === (string) $newValue;
                } elseif (in_array($field, self::STRICT_FIELDS, true)) {
                    $newValue = $field === 'cooking_minutes' ? MenuExtras::minutes($newValue) : MenuExtras::day($newValue);
                    $sameValue = $oldComparable === $newValue
                        || ($oldComparable !== null && $newValue !== null && (string) $oldComparable === (string) $newValue);
                } else {
                    $sameValue = $oldComparable == $newValue;
                }
                if ($sameValue) {
                    continue;
                }

                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $product->{$field} = $newValue;
            }

            if ($changes === []) {
                return $product->fresh();
            }

            $product->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $product->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            // Fix order 1, L3 — leaving a recipe type clears the recipe on
            // the server, through the recipe action: a version row and a
            // recipe audit row name the actor, so the recipe history shows
            // it. A stale recipe would otherwise keep driving the product
            // badge, Recipe & Cost and the cost fallback — and come back to
            // life if the type is switched back.
            if ($hasRecipe && in_array($oldMode, self::RECIPE_MODES, true) && ! in_array($newMode, self::RECIPE_MODES, true)) {
                $this->updateRecipe->handle(
                    $product,
                    [],
                    $actor,
                    sprintf('Recipe removed: the product type changed from %s to %s.', self::modeLabel($oldMode), self::modeLabel($newMode)),
                );
            }

            return $product->fresh();
        });
    }

    private static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'ingredient' => 'made to order',
            'cooked' => 'cooked',
            'unit' => 'ready / bought-in',
            'untracked' => 'no stock tracking',
            default => $mode,
        };
    }
}
