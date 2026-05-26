<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * DTO for writing an audit log row. Identical shape to pos_admin's
 * AuditLogData so both apps land rows in the same {@see \App\Models\AuditLog}
 * table with a consistent envelope.
 *
 * IP + user agent default to NULL; the action layer fills them
 * from {@see \App\Support\AuditContext} when the writing call is
 * inside an HTTP request.
 */
final class AuditLogData extends Data
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $event,
        public readonly ?int $actorUserId = null,
        public readonly ?int $companyId = null,
        public readonly ?int $branchId = null,
        public readonly ?string $auditableType = null,
        public readonly ?int $auditableId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?array $oldValues = null,
        public readonly ?array $newValues = null,
        public readonly ?array $metadata = null,
    ) {}
}
