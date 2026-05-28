<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerLoyaltyConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6b — per-company loyalty config (singleton).
 *
 * The unique(company_id) constraint at the DB level enforces
 * "at most one row per merchant"; the Action layer enforces
 * find-or-create semantics ("upsert").
 *
 * Money is reasoned about in integers here:
 *   - points_per_omr    : whole points earned per 1 OMR spent
 *   - baisas_per_point  : how many baisas (1/1000 OMR) one
 *                          point is worth on redemption
 *
 * No float math at any point in the loyalty pipeline.
 *
 * Schema owned by pos_admin's 2026_06_02_010000 migration.
 */
#[Fillable([
    'company_id',
    'points_per_omr',
    'baisas_per_point',
    'is_active',
])]
class CustomerLoyaltyConfig extends Model
{
    /** @use HasFactory<CustomerLoyaltyConfigFactory> */
    use HasFactory;

    protected $table = 'pos_customer_loyalty_configs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points_per_omr' => 'integer',
            'baisas_per_point' => 'integer',
            'is_active' => 'boolean',
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
