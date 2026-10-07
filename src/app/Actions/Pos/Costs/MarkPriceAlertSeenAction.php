<?php

declare(strict_types=1);

namespace App\Actions\Pos\Costs;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\PurchaseReceiptLine;
use App\Models\User;
use App\Support\Costs\CostSettings;
use App\Support\Costs\PriceHistory;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH costs & allergens add-on (tester call 1) — a manager marks a price
 * alert as seen: one pos_price_alert_reviews row per receipt line (who,
 * when), audited (inventory.price_alert.seen) with the ingredient, the old
 * and new price per base unit and the change. Marking it again changes
 * nothing. Only an alerting ingredient line of the merchant's own receipt.
 */
final readonly class MarkPriceAlertSeenAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /** @return bool true when this call marked it (false = it was already seen) */
    public function handle(int $lineId, User $actor): bool
    {
        $companyId = $this->tenant->requiredId();
        $owned = DB::table('pos_purchase_receipt_lines as l')->join('pos_purchase_receipts as r', 'r.id', '=', 'l.purchase_receipt_id')
            ->where('l.id', $lineId)->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->where('l.item_type', 'ingredient')->exists();
        if (! $owned) {
            abort(404);
        }
        $row = PriceHistory::alerts($companyId, now(), lineIds: [$lineId])->first();
        if ($row === null) {
            throw new RuntimeException('This purchase line is not a price alert.');
        }

        return DB::transaction(function () use ($companyId, $lineId, $actor, $row): bool {
            // Fix order 1 (K-5) — insert-or-ignore on the UNIQUE line: two
            // managers pressing "Mark as seen" at once both get 200; only the
            // one that wrote the row is audited.
            $now = now();
            $written = DB::table('pos_price_alert_reviews')->insertOrIgnore([
                'company_id' => $companyId, 'purchase_receipt_line_id' => $lineId, 'seen_by_user_id' => (int) $actor->getKey(),
                'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($written === 0) {
                return false;
            }
            $id = DB::table('pos_price_alert_reviews')->where('purchase_receipt_line_id', $lineId)->value('id');
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.price_alert.seen',
                actorUserId: (int) $actor->getKey(),
                companyId: $companyId,
                auditableType: PurchaseReceiptLine::class,
                auditableId: $lineId,
                newValues: [
                    'review_id' => (int) $id,
                    'ingredient_id' => (int) $row->ingredient_id,
                    'old_unit_cost' => $row->previous_unit_cost,
                    'new_unit_cost' => $row->unit_cost,
                    'change_pct' => $row->change_pct,
                    'threshold_percent' => CostSettings::threshold($companyId),
                ],
            ));

            return true;
        });
    }
}
