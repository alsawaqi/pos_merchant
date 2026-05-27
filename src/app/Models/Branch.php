<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only projection of a `pos_branches` row, shared with
 * pos_admin which owns the schema + writes.
 *
 * pos_merchant queries this table to:
 *   - power the branch-scope multi-select on the Portal Users
 *     create modal (which branches the new user can access)
 *   - read the merchant's own branch list for the Branches read-
 *     only view (Phase 4.6)
 *
 * Soft-deleted branches are hidden by default — the merchant
 * never sees branches pos_admin retired.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_branches';

    // We never CREATE branches from this app — that's a pos_admin-
    // only responsibility. Empty fillable + explicit guard keeps
    // accidental mass-assignment from mutating the shared table.
    protected $fillable = [];
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'country_id' => 'integer',
            'region_id' => 'integer',
            'district_id' => 'integer',
            'city_id' => 'integer',
            'geofence_radius_m' => 'integer',
            'opening_hours_json' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
