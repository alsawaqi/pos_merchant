<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Pos\DeliveryProviders\SetProductDeliveryPriceAction;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PD1 — the 3-step product wizard's ATOMIC create: composes the
 * existing single-purpose actions (product, shared-group sync, owned
 * groups + options, recipe, physical items, branches, delivery prices)
 * inside ONE transaction, so a failure anywhere — a foreign linked
 * product on option 3 of group 2, a bad ingredient uuid — rolls the
 * whole product back instead of leaving the half-configured orphan the
 * old 6-call modal chain could produce.
 *
 * Every business guard (tenant re-checks, unit-mode components,
 * non-internal linked products, single-default sweep, per-section
 * audit rows) lives in the composed actions and applies unchanged;
 * their RuntimeExceptions bubble out of the transaction to the
 * controller's 422. Edit mode keeps using the per-section endpoints —
 * an existing product is retry-safe, a brand-new one is not.
 */
final readonly class CreateProductWizardAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
        private CreateProductAction $createProduct,
        private SyncProductAddOnGroupsAction $syncAddOnGroups,
        private CreateAddOnGroupAction $createAddOnGroup,
        private CreateAddOnAction $createAddOn,
        private UpdateProductRecipeAction $updateRecipe,
        private UpdateProductComponentsAction $updateComponents,
        private SyncProductBranchesAction $syncBranches,
        private SetProductDeliveryPriceAction $setDeliveryPrice,
        private SyncRemovableIngredientsAction $syncRemovable,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  validated CreateProductWizardRequest data
     */
    public function handle(array $payload, User $actor): Product
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($payload, $actor, $companyId): Product {
            $product = $this->createProduct->handle($payload['product'], $actor);

            $sharedUuids = $payload['addon_group_uuids'] ?? [];
            if ($sharedUuids !== []) {
                $this->syncAddOnGroups->handle($product, $sharedUuids, $actor);
            }

            foreach ($payload['owned_groups'] ?? [] as $groupPayload) {
                $group = $this->createAddOnGroup->handle([
                    'name' => $groupPayload['name'],
                    'name_ar' => $groupPayload['name_ar'] ?? null,
                    'selection_mode' => $groupPayload['selection_mode'] ?? null,
                    'min_selections' => $groupPayload['min_selections'] ?? null,
                    'max_selections' => $groupPayload['max_selections'] ?? null,
                    'display_order' => $groupPayload['display_order'] ?? 0,
                    'owner_product_id' => $product->id,
                ], $actor);

                foreach ($groupPayload['options'] ?? [] as $optionPayload) {
                    $this->createAddOn->handle($group, $optionPayload, $actor);
                }
            }

            $recipeLines = $payload['recipe_lines'] ?? [];
            if ($recipeLines !== []) {
                $this->updateRecipe->handle($product, $recipeLines, $actor, $payload['recipe_note'] ?? null);
            }

            // LAUNCH review add-on — the lines ticked "Can be removed".
            $removable = $payload['removable'] ?? [];
            if (is_array($removable) && $removable !== []) {
                $this->syncRemovable->handle($product, array_values($removable), $actor);
            }

            $componentLines = $payload['component_lines'] ?? [];
            if ($componentLines !== []) {
                $this->updateComponents->handle($product, $componentLines, $actor);
            }

            // NULL = skip (every branch, the default rule). LAUNCH-P4 H6 —
            // otherwise { branch_scope, branch_ids }. The controller has
            // already 403'd a non-null payload from a branch-restricted user.
            if (array_key_exists('branches', $payload) && $payload['branches'] !== null) {
                // [] (the old "every branch" payload) = 'all'.
                $this->syncBranches->handle(
                    $product,
                    (string) ($payload['branches']['branch_scope'] ?? Product::SCOPE_ALL),
                    $payload['branches']['branch_ids'] ?? [],
                    $actor,
                );
            }

            // LAUNCH-P4 B3 — per provider: listed (false hides the product
            // there) and an optional price (NULL = the delivery price).
            foreach ($payload['delivery_prices'] ?? [] as $pricePayload) {
                $provider = DeliveryProvider::query()
                    ->where('company_id', $companyId)
                    ->where('uuid', (string) $pricePayload['provider_uuid'])
                    ->first();
                if ($provider === null) {
                    throw new RuntimeException('A delivery provider in the pricing list does not belong to your company.');
                }

                $this->setDeliveryPrice->handle(
                    $product,
                    $provider,
                    $pricePayload['price'] ?? null,
                    $actor,
                    (bool) ($pricePayload['listed'] ?? true),
                );
            }

            return $product;
        });
    }
}
