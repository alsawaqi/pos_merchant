<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Shared with pos_admin via the `pos_audit_logs` table — every
 * audit-able action by either app writes to the same ledger so
 * pos_admin's Audit Log viewer can read merchant-portal events
 * alongside platform actions.
 *
 * Immutable by design: the booted() guards throw on update +
 * delete. Always written through {@see \App\Actions\Security\WriteAuditLogAction}.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pos_audit_logs';

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Audit log entries are immutable.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Audit log entries cannot be deleted.');
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_user_id',
        'company_id',
        'branch_id',
        'event',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
