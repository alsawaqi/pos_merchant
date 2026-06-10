<?php

declare(strict_types=1);

namespace App\Actions\Pos\OrderReasons;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Models\VoidReason;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase B — create or update a void reason for the actor's company.
 *
 * The stable `code` is minted from the name on create (slug, 32-char
 * cap) and is IMMUTABLE thereafter — voided orders snapshot it.
 * Audit events: settings.void_reason.created / .updated.
 */
final readonly class SaveVoidReasonAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name?: string, name_ar?: string|null, affects_inventory?: bool, requires_manager?: bool, is_active?: bool, sort_order?: int}  $attributes
     */
    public function handle(array $attributes, User $actor, ?VoidReason $existing = null): VoidReason
    {
        $companyId = $this->tenant->requiredId();
        if ($existing !== null && (int) $existing->company_id !== $companyId) {
            abort(404);
        }

        $name = trim((string) ($attributes['name'] ?? $existing?->name ?? ''));
        if ($name === '') {
            throw new RuntimeException('Void reason name is required.');
        }

        $code = $existing?->code ?? substr(Str::slug($name, '_'), 0, 32);

        $duplicate = VoidReason::query()
            ->where('company_id', $companyId)
            ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
            ->where(function ($query) use ($name, $code): void {
                $query->where('name', $name)->orWhere('code', $code);
            })
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A void reason with this name already exists.');
        }

        return DB::transaction(function () use ($attributes, $existing, $name, $code, $actor, $companyId): VoidReason {
            $values = [
                'name' => $name,
                'name_ar' => array_key_exists('name_ar', $attributes) ? $attributes['name_ar'] : $existing?->name_ar,
                'affects_inventory' => (bool) ($attributes['affects_inventory'] ?? $existing?->affects_inventory ?? false),
                'requires_manager' => (bool) ($attributes['requires_manager'] ?? $existing?->requires_manager ?? true),
                'is_active' => (bool) ($attributes['is_active'] ?? $existing?->is_active ?? true),
                'sort_order' => (int) ($attributes['sort_order'] ?? $existing?->sort_order ?? 0),
            ];

            if ($existing === null) {
                /** @var VoidReason $reason */
                $reason = VoidReason::query()->create([
                    'company_id' => $companyId,
                    'code' => $code,
                    ...$values,
                ]);
            } else {
                $existing->fill($values)->save();
                $reason = $existing;
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: $existing === null ? 'settings.void_reason.created' : 'settings.void_reason.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: VoidReason::class,
                auditableId: $reason->id,
                newValues: ['code' => $code, ...$values],
            ));

            return $reason->fresh();
        });
    }
}
