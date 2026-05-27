<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `pos_branches` row. Schema owned by pos_admin's migrations;
 * pos_merchant reads it and — as of Phase 4.7 — writes a narrow
 * whitelist of operational fields via UpdateMerchantBranchAction
 * (rename, contact details, hours, geo, default order type,
 * status flip with extra permission gate).
 *
 * Reach for {@see \App\Actions\Pos\Branch\UpdateMerchantBranchAction}
 * rather than calling ::update / ::save directly from a
 * controller. The Action enforces the merchant-editable
 * whitelist + writes the `branch.updated` audit row + gates
 * status transitions on the separate
 * `MerchantPermission.BranchesTransitionStatus` permission.
 *
 * Soft-deleted branches are hidden by default — the merchant
 * never sees branches pos_admin retired.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_branches';

    /**
     * Mass-assignment whitelist — the EXACT set of fields the
     * merchant portal is allowed to PATCH. Everything else
     * (uuid, code, company_id, region/country/district/city,
     * audit timestamps) stays admin-territory and must never
     * land in this list.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'name_ar',
        'manager_name',
        'phone',
        'email',
        'address',
        'latitude',
        'longitude',
        'geofence_radius_m',
        'opening_hours_json',
        'default_order_type',
        'status',
        'settings',
    ];

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
            'status' => BranchStatus::class,
            'default_order_type' => BranchOrderType::class,
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * UUID is what the merchant portal binds in the URL — internal
     * ids stay out of the address bar.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
