<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase 6a — customer master at company level (blueprint §6.1).
 *
 * Per-merchant customer book. Same tenancy model as ingredients:
 * each merchant owns their own list, no cross-company sharing.
 *
 * Minimum-scope fields for Phase 6a:
 *   - name + phone (both required)
 *
 * Phone is the natural lookup key at the POS counter — see the
 * unique constraint on (company_id, phone) which makes find-or-
 * create a one-shot.
 *
 * Vehicle plates live in pos_customer_vehicle_plates with a 1:N
 * relationship; see the plates() helper below.
 *
 * Soft delete: Phase 7+ order rows will reference customer_id;
 * never hard-delete or historical sales reports break.
 *
 * Schema owned by pos_admin's 2026_06_01_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'phone',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_customers';

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
     * @return HasMany<CustomerVehiclePlate, $this>
     */
    public function vehiclePlates(): HasMany
    {
        return $this->hasMany(CustomerVehiclePlate::class)
            ->orderBy('id');
    }
}
