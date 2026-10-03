<?php

declare(strict_types=1);

namespace App\Actions\Pos\Taxes;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\Tax;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P4 B1 — the one-click "Add VAT 5%" on the Taxes page, offered when a
 * VAT-registered company has no active tax row (so it charges nothing).
 *
 * Creates "VAT / ضريبة القيمة المضافة" at 5% — the same row pos_admin creates
 * when it registers a company with no tax row. A "VAT" row that already exists
 * (inactive, or soft-deleted: the (company_id, name) unique index still holds
 * its name) is switched back on at 5% instead of colliding with it.
 *
 * Refused (RuntimeException → 422) when the company is not VAT-registered or
 * already has an active tax. Audit: settings.tax.vat_added.
 */
final readonly class AddStandardVatAction
{
    public const NAME = 'VAT';

    public const NAME_AR = 'ضريبة القيمة المضافة';

    public const RATE = '5.00';

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(User $actor): Tax
    {
        $companyId = $this->tenant->requiredId();

        $company = Company::query()->findOrFail($companyId);
        if ($company->vat_registered_at === null) {
            throw new RuntimeException('Your business is not VAT-registered, so no VAT is charged. Contact support to register the VAT number first.');
        }

        return DB::transaction(function () use ($companyId, $actor): Tax {
            $hasActive = Tax::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();
            if ($hasActive) {
                throw new RuntimeException('An active tax already exists.');
            }

            /** @var Tax|null $existing */
            $existing = Tax::query()
                ->withTrashed()
                ->where('company_id', $companyId)
                ->where('name', self::NAME)
                ->first();

            if ($existing !== null) {
                $old = [
                    'rate_percent' => (string) $existing->rate_percent,
                    'is_active' => (bool) $existing->is_active,
                    'deleted' => $existing->trashed(),
                ];
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->forceFill([
                    'rate_percent' => self::RATE,
                    'is_active' => true,
                    'name_ar' => $existing->name_ar ?: self::NAME_AR,
                ])->save();
                $tax = $existing;
            } else {
                $old = null;
                /** @var Tax $tax */
                $tax = Tax::query()->create([
                    'company_id' => $companyId,
                    'name' => self::NAME,
                    'name_ar' => self::NAME_AR,
                    'rate_percent' => self::RATE,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.tax.vat_added',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Tax::class,
                auditableId: $tax->id,
                oldValues: $old,
                newValues: ['name' => self::NAME, 'rate_percent' => self::RATE, 'is_active' => true],
            ));

            return $tax->fresh();
        });
    }
}
