<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Meal;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalogue\ComboLinesInput;
use App\Support\Catalogue\MealMains;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH combo add-on (owner decision 4) — create or update a meal in ONE
 * transaction: the meal row, its categories, its unticked mains and its
 * lines. The clash rule is checked again under a per-company lock (two
 * managers saving at once). Audited as catalogue.meal.saved.
 */
final readonly class SaveMealAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated SaveMealRequest data
     */
    public function handle(?Meal $meal, array $data, User $actor): Meal
    {
        $companyId = $this->tenant->requiredId();
        if ($meal !== null && (int) $meal->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($meal, $data, $actor, $companyId): Meal {
            // Serialise the meal saves of one merchant (the clash rule).
            DB::table('pos_companies')->where('id', $companyId)->lockForUpdate()->first(['id']);
            $before = $meal === null ? null : $this->snapshot($meal);
            $fields = [
                'name' => trim((string) $data['name']),
                'name_ar' => trim((string) ($data['name_ar'] ?? '')) === '' ? null : trim((string) $data['name_ar']),
                'meal_price' => number_format((float) $data['meal_price'], 3, '.', ''),
                'on_sale_from' => $data['on_sale_from'] ?? null,
                'on_sale_until' => $data['on_sale_until'] ?? null,
            ];
            if (array_key_exists('status', $data)) {
                $fields['status'] = $data['status'];
            }
            if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
                $fields['sort_order'] = (int) $data['sort_order'];
            }
            if ($meal === null) {
                $meal = Meal::query()->create($fields + ['company_id' => $companyId, 'status' => Meal::STATUS_ACTIVE]);
            } else {
                $meal->forceFill($fields)->save();
            }

            $categoryIds = array_values(array_unique(array_map('intval', (array) $data['category_ids'])));
            $excluded = Product::query()->where('company_id', $companyId)->whereIn('uuid', (array) $data['excluded_product_uuids'])
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $meal->categories()->sync(array_fill_keys($categoryIds, ['company_id' => $companyId]));
            $meal->excludedProducts()->sync(array_fill_keys($excluded, ['company_id' => $companyId]));
            ComboLinesInput::save(['meal_id' => (int) $meal->id], (array) $data['lines'], $companyId);

            if ($meal->status === Meal::STATUS_ACTIVE) {
                $clashes = MealMains::clashes($companyId, (int) $meal->id, $categoryIds, $excluded, $meal->on_sale_from, $meal->on_sale_until);
                if ($clashes !== []) {
                    throw new RuntimeException(MealMains::message($clashes[0]));
                }
            }

            $after = $this->snapshot($meal->fresh());
            if ($before !== $after) {
                $meal->touch();
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.meal.saved',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Meal::class,
                    auditableId: $meal->id,
                    oldValues: $before === null ? [] : ['meal' => $before],
                    newValues: ['meal' => $after],
                ));
            }

            return $meal->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Meal $meal): array
    {
        return [
            'name' => $meal->name,
            'name_ar' => $meal->name_ar,
            'meal_price' => (string) $meal->meal_price,
            'status' => $meal->status,
            'on_sale_from' => $meal->on_sale_from?->format('Y-m-d'),
            'on_sale_until' => $meal->on_sale_until?->format('Y-m-d'),
            'category_ids' => $meal->categories()->pluck('pos_product_categories.id')->map(static fn ($id): int => (int) $id)->sort()->values()->all(),
            'excluded_product_ids' => $meal->excludedProducts()->pluck('pos_products.id')->map(static fn ($id): int => (int) $id)->sort()->values()->all(),
            'lines' => ComboLinesInput::present(['meal_id' => (int) $meal->id]),
        ];
    }
}
