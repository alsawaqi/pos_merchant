<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A per-user saved filter preset for one portal screen.
 *
 * Personal: every row belongs to one (company_id, user_id). `view_key` names
 * the screen, `filters` is the opaque JSON the screen re-applies, `is_default`
 * marks the preset auto-applied on that screen's first load (at most one per
 * user+view_key, enforced by the controller).
 *
 * Schema owned by pos_admin (2026_06_14_010000).
 */
#[Fillable([
    'uuid',
    'company_id',
    'user_id',
    'view_key',
    'name',
    'filters',
    'is_default',
])]
class SavedView extends Model
{
    protected $table = 'pos_saved_views';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if ($row->uuid === null || $row->uuid === '') {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
