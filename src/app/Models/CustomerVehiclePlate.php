<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerVehiclePlateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 6a — vehicle plate row attached to a customer.
 *
 * 1:N to pos_customers. company_id is denormalised from the
 * parent customer so the (company_id, plate_number) unique
 * constraint can enforce real-world plate uniqueness within a
 * tenant — and the "find customer by plate" drive-thru lookup
 * is a single index hit + FK follow.
 *
 * The plate_number is stored as the canonical form chosen by
 * the Action layer (trim + uppercase). Display formatting is
 * a UI concern.
 *
 * Schema owned by pos_admin's 2026_06_01_010100 migration.
 */
#[Fillable([
    'uuid',
    'customer_id',
    'company_id',
    'plate_number',
])]
class CustomerVehiclePlate extends Model
{
    /** @use HasFactory<CustomerVehiclePlateFactory> */
    use HasFactory;

    protected $table = 'pos_customer_vehicle_plates';

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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
