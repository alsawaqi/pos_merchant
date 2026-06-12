<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P-G6 — a staff-announcement read receipt ("sent is not the same as
 * seen"). Written by pos_api when the staff member opens the message on a
 * till; the portal renders "read by N / who" from these rows.
 */
#[Fillable([
    'staff_message_id',
    'staff_id',
    'device_id',
    'read_at',
])]
class StaffMessageRead extends Model
{
    protected $table = 'pos_staff_message_reads';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'staff_id');
    }
}
