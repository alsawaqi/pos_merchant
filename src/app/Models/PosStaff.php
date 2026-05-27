<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffPosition;
use App\Enums\StaffStatus;
use Database\Factories\PosStaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * PIN-authenticated POS staff row.
 *
 * Schema is owned by pos_admin's migration
 * 2026_05_27_010000_create_pos_staff_table — this app only
 * reads + writes via the explicit Actions in
 * App\Actions\Pos\Staff\*.
 *
 * The pin_hash column is intentionally NEVER serialised. The
 * one-shot plaintext PIN is returned by Create/ResetPin actions
 * as a separate top-level envelope field so the SPA has to
 * consciously handle it, and never stored on disk.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'name',
    'phone',
    'staff_code',
    'pin_hash',
    'position',
    'status',
    'hired_at',
    'terminated_at',
    'last_login_at',
    'created_by_user_id',
])]
#[Hidden(['pin_hash'])]
class PosStaff extends Model
{
    /** @use HasFactory<PosStaffFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_staff';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => StaffPosition::class,
            'status' => StaffStatus::class,
            'hired_at' => 'date',
            'terminated_at' => 'datetime',
            'last_login_at' => 'datetime',
            // PII at rest. TEXT column on the DB side absorbs the
            // ~3× ciphertext expansion. See
            // 2026_05_26_030000_widen_pii_columns_for_encryption
            // for the broader PII inventory.
            'phone' => 'encrypted',
        ];
    }

    /**
     * Auto-mint a uuid on insert if the caller didn't supply
     * one. The migration declares the column unique so a missing
     * one would otherwise blow up under a parallel test seed.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $staff): void {
            if ($staff->uuid === null || $staff->uuid === '') {
                $staff->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Route binding by uuid — keeps internal ids out of URLs the
     * merchant sees, and matches how Branches + Devices already
     * resolve elsewhere in the codebase.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Which portal user enrolled this staff row. Nullable for
     * historical rows (or if the creator was later deleted —
     * the FK is ON DELETE SET NULL).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Scope to currently-active staff. Suspended + terminated
     * rows are filtered out — this is the right default for the
     * POS device's "who can clock in?" lookup.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StaffStatus::Active->value);
    }
}
