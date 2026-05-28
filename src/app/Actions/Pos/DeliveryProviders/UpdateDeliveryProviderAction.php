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
 * Phase 6c — partial-update a delivery provider with diff-
 * aware audit. Same pattern as UpdateSupplierAction.
 *
 * Name change re-checks (company_id, name) uniqueness excluding
 * self — DB constraint backs us up under concurrent writes.
 */
final readonly class UpdateDeliveryProviderAction
{
    private const MUTABLE_FIELDS = ['name', 'color', 'is_active', 'sort_order'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(DeliveryProvider $provider, array $attributes, User $actor): DeliveryProvider
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $provider->company_id !== $companyId) {
            abort(404);
        }

        if (array_key_exists('name', $attributes)) {
            $newName = trim((string) $attributes['name']);
            if ($newName === '') {
                throw new RuntimeException('Provider name is required.');
            }
            $attributes['name'] = $newName;
            if ($newName !== $provider->name) {
                $duplicate = DeliveryProvider::query()
                    ->where('company_id', $companyId)
                    ->where('name', $newName)
                    ->where('id', '!=', $provider->id)
                    ->exists();
                if ($duplicate) {
                    throw new RuntimeException('Another delivery provider with this name already exists.');
                }
            }
        }

        return DB::transaction(function () use ($provider, $attributes, $actor, $companyId): DeliveryProvider {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                // Cast incoming bool/int so a "1" string from
                // JSON doesn't read as different from a true bool.
                $newValue = match ($field) {
                    'is_active' => (bool) $attributes[$field],
                    'sort_order' => (int) $attributes[$field],
                    default => $attributes[$field],
                };
                if ($provider->{$field} == $newValue) {
                    continue;
                }
                $changes[$field] = ['old' => $provider->{$field}, 'new' => $newValue];
                $provider->{$field} = $newValue;
            }

            if ($changes === []) {
                return $provider->fresh();
            }

            $provider->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.delivery_provider.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: DeliveryProvider::class,
                auditableId: $provider->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $provider->fresh();
        });
    }
}
