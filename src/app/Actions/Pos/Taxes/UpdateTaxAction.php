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
 * Partial-update a company tax with diff-aware audit. Same pattern as
 * UpdateDeliveryProviderAction. A name change re-checks (company_id, name)
 * uniqueness excluding self. Audit event: settings.tax.updated.
 */
final readonly class UpdateTaxAction
{
    private const MUTABLE_FIELDS = ['name', 'name_ar', 'rate_percent', 'is_active', 'sort_order'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Tax $tax, array $attributes, User $actor): Tax
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $tax->company_id !== $companyId) {
            abort(404);
        }

        if (array_key_exists('name', $attributes)) {
            $newName = trim((string) $attributes['name']);
            if ($newName === '') {
                throw new RuntimeException('Tax name is required.');
            }
            $attributes['name'] = $newName;
            if ($newName !== $tax->name) {
                $duplicate = Tax::query()
                    ->where('company_id', $companyId)
                    ->where('name', $newName)
                    ->where('id', '!=', $tax->id)
                    ->exists();
                if ($duplicate) {
                    throw new RuntimeException('Another tax with this name already exists.');
                }
            }
        }

        return DB::transaction(function () use ($tax, $attributes, $actor, $companyId): Tax {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                // Normalise incoming values so a JSON "1"/5 doesn't read as a
                // change vs the stored bool / decimal:2 string.
                $newValue = match ($field) {
                    'is_active' => (bool) $attributes[$field],
                    'sort_order' => (int) $attributes[$field],
                    'rate_percent' => number_format((float) $attributes[$field], 2, '.', ''),
                    'name_ar' => $attributes[$field] === null ? null : (string) $attributes[$field],
                    default => (string) $attributes[$field],
                };
                if ($tax->{$field} == $newValue) {
                    continue;
                }
                $changes[$field] = ['old' => $tax->{$field}, 'new' => $newValue];
                $tax->{$field} = $newValue;
            }

            if ($changes === []) {
                return $tax->fresh();
            }

            $tax->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.tax.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Tax::class,
                auditableId: $tax->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $tax->fresh();
        });
    }
}
