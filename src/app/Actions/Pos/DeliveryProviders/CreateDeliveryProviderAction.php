<?php

declare(strict_types=1);

namespace App\Actions\Pos\DeliveryProviders;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeliveryProvider;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6c — create a delivery provider for the actor's company.
 *
 * Pre-flight (company_id, name) duplicate check so the error
 * is friendlier than the raw unique-constraint violation. The
 * DB constraint still backs us up under concurrent writes.
 *
 * Audit event: catalogue.delivery_provider.created.
 */
final readonly class CreateDeliveryProviderAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, color?: string|null, commission_percent?: numeric, is_active?: bool, sort_order?: int}  $attributes
     */
    public function handle(array $attributes, User $actor): DeliveryProvider
    {
        $companyId = $this->tenant->requiredId();

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Provider name is required.');
        }

        $duplicate = DeliveryProvider::query()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A delivery provider with this name already exists.');
        }

        return DB::transaction(function () use ($attributes, $name, $actor, $companyId): DeliveryProvider {
            /** @var DeliveryProvider $provider */
            $provider = DeliveryProvider::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'color' => $attributes['color'] ?? null,
                // P-G7 — the provider's cut of every delivery order.
                'commission_percent' => $attributes['commission_percent'] ?? 0,
                'is_active' => $attributes['is_active'] ?? true,
                'sort_order' => $attributes['sort_order'] ?? 0,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.delivery_provider.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: DeliveryProvider::class,
                auditableId: $provider->id,
                newValues: [
                    'name' => $name,
                    'color' => $attributes['color'] ?? null,
                    'commission_percent' => (float) ($attributes['commission_percent'] ?? 0),
                    'is_active' => (bool) ($attributes['is_active'] ?? true),
                ],
            ));

            return $provider->fresh();
        });
    }
}
