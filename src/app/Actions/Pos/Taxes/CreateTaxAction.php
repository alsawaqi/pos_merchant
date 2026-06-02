<?php

declare(strict_types=1);

namespace App\Actions\Pos\Taxes;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Tax;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create a company tax for the actor's company.
 *
 * Pre-flight (company_id, name) duplicate check so the error is friendlier than
 * the raw unique-constraint violation; the DB constraint still backs us up under
 * concurrent writes. Audit event: settings.tax.created.
 */
final readonly class CreateTaxAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, rate_percent: mixed, is_active?: bool, sort_order?: int}  $attributes
     */
    public function handle(array $attributes, User $actor): Tax
    {
        $companyId = $this->tenant->requiredId();

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Tax name is required.');
        }

        $duplicate = Tax::query()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A tax with this name already exists.');
        }

        return DB::transaction(function () use ($attributes, $name, $actor, $companyId): Tax {
            /** @var Tax $tax */
            $tax = Tax::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'name_ar' => $attributes['name_ar'] ?? null,
                'rate_percent' => $attributes['rate_percent'],
                'is_active' => $attributes['is_active'] ?? true,
                'sort_order' => $attributes['sort_order'] ?? 0,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.tax.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Tax::class,
                auditableId: $tax->id,
                newValues: [
                    'name' => $name,
                    'rate_percent' => (string) $attributes['rate_percent'],
                    'is_active' => (bool) ($attributes['is_active'] ?? true),
                ],
            ));

            return $tax->fresh();
        });
    }
}
