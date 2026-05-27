<?php

declare(strict_types=1);

namespace App\Actions\Pos\FloorPlan;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Models\Floor;
use App\Models\Table;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Add a table to a floor. The QR token is auto-minted by
 * the model's booted() hook + the DB's UNIQUE on qr_token
 * means a collision (cryptographically negligible at 24 url-
 * safe chars) would bubble up as a constraint violation —
 * the caller can simply retry.
 *
 * Audit event: table.created. Note: qr_token NEVER appears
 * in audit logs — even though it's not strictly a credential,
 * it's effectively a bearer token for scan-to-order so we
 * treat it with the same "never leaks to audit" hygiene as
 * passwords + PINs.
 */
final readonly class CreateTableAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{label: string, seats?: int, min_party?: int|null, max_party?: int|null, shape?: string, notes?: string|null, display_order?: int}  $attributes
     */
    public function handle(Floor $floor, array $attributes, User $actor): Table
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $floor->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($floor, $attributes, $actor, $companyId): Table {
            /** @var Table $table */
            $table = Table::query()->create([
                'company_id' => $companyId,
                'floor_id' => $floor->id,
                'label' => $attributes['label'],
                'seats' => $attributes['seats'] ?? 4,
                'min_party' => $attributes['min_party'] ?? null,
                'max_party' => $attributes['max_party'] ?? null,
                'shape' => $attributes['shape'] ?? TableShape::Square->value,
                'notes' => $attributes['notes'] ?? null,
                'status' => TableStatus::Active->value,
                'display_order' => $attributes['display_order'] ?? 0,
                // qr_token auto-minted by Table model's
                // booted() hook — pass-through to keep this
                // action stateless against the generator.
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'table.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $floor->branch_id,
                auditableType: Table::class,
                auditableId: $table->id,
                newValues: [
                    'floor_id' => $table->floor_id,
                    'label' => $table->label,
                    'seats' => $table->seats,
                    'shape' => $table->shape?->value,
                    // intentionally omit qr_token
                ],
            ));

            return $table;
        });
    }
}
