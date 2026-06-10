<?php

declare(strict_types=1);

namespace App\Actions\Pos\OrderReasons;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CompReason;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase B — create or update a comp reason for the actor's company.
 * Same shape as {@see SaveVoidReasonAction}; `code` is minted once
 * and immutable (order comps snapshot it). Audit events:
 * settings.comp_reason.created / .updated.
 */
final readonly class SaveCompReasonAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name?: string, name_ar?: string|null, max_amount?: string|float|int|null, is_active?: bool, sort_order?: int}  $attributes
     */
    public function handle(array $attributes, User $actor, ?CompReason $existing = null): CompReason
    {
        $companyId = $this->tenant->requiredId();
        if ($existing !== null && (int) $existing->company_id !== $companyId) {
            abort(404);
        }

        $name = trim((string) ($attributes['name'] ?? $existing?->name ?? ''));
        if ($name === '') {
            throw new RuntimeException('Comp reason name is required.');
        }

        $code = $existing?->code ?? substr(Str::slug($name, '_'), 0, 32);

        $duplicate = CompReason::query()
            ->where('company_id', $companyId)
            ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
            ->where(function ($query) use ($name, $code): void {
                $query->where('name', $name)->orWhere('code', $code);
            })
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A comp reason with this name already exists.');
        }

        return DB::transaction(function () use ($attributes, $existing, $name, $code, $actor, $companyId): CompReason {
            $maxAmount = array_key_exists('max_amount', $attributes)
                ? $attributes['max_amount']
                : $existing?->max_amount;
            $values = [
                'name' => $name,
                'name_ar' => array_key_exists('name_ar', $attributes) ? $attributes['name_ar'] : $existing?->name_ar,
                'max_amount' => ($maxAmount === null || $maxAmount === '') ? null : $maxAmount,
                'is_active' => (bool) ($attributes['is_active'] ?? $existing?->is_active ?? true),
                'sort_order' => (int) ($attributes['sort_order'] ?? $existing?->sort_order ?? 0),
            ];

            if ($existing === null) {
                /** @var CompReason $reason */
                $reason = CompReason::query()->create([
                    'company_id' => $companyId,
                    'code' => $code,
                    ...$values,
                ]);
            } else {
                $existing->fill($values)->save();
                $reason = $existing;
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: $existing === null ? 'settings.comp_reason.created' : 'settings.comp_reason.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: CompReason::class,
                auditableId: $reason->id,
                newValues: [
                    'code' => $code,
                    'name' => $values['name'],
                    'max_amount' => $values['max_amount'] !== null ? (string) $values['max_amount'] : null,
                    'is_active' => $values['is_active'],
                ],
            ));

            return $reason->fresh();
        });
    }
}
