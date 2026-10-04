<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH-P5 — one clock-in / clock-out of a staff member
 * (pos_staff_attendance). Devices write it through the outbox
 * (staff.clock_in / staff.clock_out, source = device); the portal only
 * corrects times, with a reason, audited (edited_by_user_id, edit_reason).
 *
 * Schema owned by pos_admin's P5 migration. `flags` is pos_api's JSON
 * (e.g. "no_clock_out"); the portal reads it, never writes it.
 */
class StaffAttendance extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_staff_attendance';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'flags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'staff_id')->withTrashed();
    }
}
