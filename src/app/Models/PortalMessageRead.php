<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * P-G6 — a portal-inbox read mark: user X opened message Y. One row per
 * (message, user); marking again is a no-op upsert.
 */
#[Fillable([
    'portal_message_id',
    'user_id',
    'read_at',
])]
class PortalMessageRead extends Model
{
    protected $table = 'pos_portal_message_reads';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
