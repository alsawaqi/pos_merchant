<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * P-G6 — a portal → device staff announcement (channel 1), composed HERE
 * and served to devices by pos_api's /device/config staff_messages slice.
 * target_type = staff | branch | company. created_by_name is the sender
 * snapshot devices render (pos_api joins no users table). Soft delete =
 * retraction: pos_api's config delta tells devices to purge the id.
 *
 * Schema owned by pos_admin (2026_07_19_000000_create_pos_messaging_tables).
 */
#[Fillable([
    'uuid',
    'company_id',
    'target_type',
    'target_branch_id',
    'target_staff_id',
    'title',
    'body',
    'created_by_user_id',
    'created_by_name',
])]
class StaffMessage extends Model
{
    use SoftDeletes;

    public const TARGET_STAFF = 'staff';

    public const TARGET_BRANCH = 'branch';

    public const TARGET_COMPANY = 'company';

    protected $table = 'pos_staff_messages';

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            if (blank($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<StaffMessageRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(StaffMessageRead::class, 'staff_message_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function targetStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'target_staff_id');
    }
}
