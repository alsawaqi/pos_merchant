<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue\MenuImport;

use App\Actions\Pos\Catalogue\CreateCategoryAction;
use App\Actions\Pos\Catalogue\CreateProductAction;
use App\Actions\Pos\Catalogue\UpdateProductAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P4 B6 — save a menu import in ONE transaction (owner decision 5).
 *
 * Plans the file again ({@see PlanMenuImportAction}); refuses (returns the
 * plan, nothing written) while any row has an error. Otherwise creates the
 * missing categories (when asked), the new products and the changed fields of
 * matched products through the normal catalogue actions (their audit rows),
 * and audits the import itself (catalogue.import.committed). Unchanged rows
 * are left alone. Any failure rolls the whole import back.
 */
final readonly class CommitMenuImportAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
        private PlanMenuImportAction $plan,
        private CreateCategoryAction $createCategory,
        private CreateProductAction $createProduct,
        private UpdateProductAction $updateProduct,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<int, list<string>>  $sheet
     * @return array{saved: bool, plan: array<string, mixed>, created: int, updated: int, unchanged: int, categories_created: int}
     */
    public function handle(array $sheet, bool $createCategories, User $actor, string $fileName): array
    {
        $plan = $this->plan->handle($sheet, $createCategories);
        if ($plan['summary']['error'] > 0) {
            return ['saved' => false, 'plan' => $plan, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'categories_created' => 0];
        }
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($plan, $actor, $companyId, $fileName): array {
            $categoryIds = [];
            foreach ($plan['new_categories'] as $category) {
                $created = $this->createCategory->handle([
                    'name' => $category['name'],
                    'name_ar' => $category['name_ar'],
                ], $actor);
                $categoryIds[mb_strtolower($category['name'])] = (int) $created->id;
            }

            $created = 0;
            $updated = 0;
            $unchanged = 0;
            foreach ($plan['rows'] as $row) {
                $attributes = $row['attributes'];
                if ($row['category_ref'] !== null) {
                    $attributes['category_id'] = $categoryIds[$row['category_ref']] ?? null;
                }

                if ($row['action'] === 'new') {
                    $this->createProduct->handle($attributes + ['stock_mode' => 'untracked'], $actor);
                    $created++;
                } elseif ($row['action'] === 'update') {
                    $product = Product::query()->where('company_id', $companyId)->findOrFail((int) $row['product_id']);
                    $this->updateProduct->handle($product, $attributes, $actor);
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.import.committed',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                newValues: [
                    'file' => mb_substr($fileName, 0, 191),
                    'created' => $created,
                    'updated' => $updated,
                    'unchanged' => $unchanged,
                    'categories_created' => count($categoryIds),
                ],
            ));

            return [
                'saved' => true,
                'plan' => $plan,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'categories_created' => count($categoryIds),
            ];
        });
    }
}
