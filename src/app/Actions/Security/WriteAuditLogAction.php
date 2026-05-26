<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Security\AuditLogData;
use App\Models\AuditLog;
use App\Support\AuditContext;

/**
 * Single entry point for every audit log write in pos_merchant.
 * Rows land in the shared `pos_audit_logs` table so pos_admin's
 * Audit Log viewer surfaces merchant-portal events alongside
 * platform actions.
 *
 * IP + user agent auto-fill from {@see AuditContext} when the
 * caller didn't pass them explicitly.
 */
final class WriteAuditLogAction
{
    public function handle(AuditLogData $data): AuditLog
    {
        /** @var AuditLog $auditLog */
        $auditLog = AuditLog::query()->create([
            'actor_user_id' => $data->actorUserId,
            'company_id' => $data->companyId,
            'branch_id' => $data->branchId,
            'event' => $data->event,
            'auditable_type' => $data->auditableType,
            'auditable_id' => $data->auditableId,
            'ip_address' => $data->ipAddress ?? AuditContext::ipAddress(),
            'user_agent' => $data->userAgent ?? AuditContext::userAgent(),
            'old_values' => $data->oldValues,
            'new_values' => $data->newValues,
            'metadata' => $data->metadata,
        ]);

        return $auditLog;
    }
}
