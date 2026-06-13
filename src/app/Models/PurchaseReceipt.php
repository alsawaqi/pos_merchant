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
 * PD6 — the Goods Received Note header (a Saved Purchase Receipt).
 *
 * One document for a whole delivery: a supplier + reference + received-at date,
 * many mixed lines (ingredients + products + physical items), and any number of
 * named extra charges. The three totals are FROZEN at save time so the document
 * reads back identically. Soft-deleted to keep it referenceable after retiring.
 *
 * Schema owned by pos_admin's 2026_07_24_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'supplier_id',
    'reference',
    'items_total',
    'charges_total',
    'grand_total',
    'status',
    'note',
    'recorded_by_user_id',
    'received_at',
])]
class PurchaseReceipt extends Model
{
    use SoftDeletes;

    protected $table = 'pos_purchase_receipts';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'items_total' => 'decimal:3',
            'charges_total' => 'decimal:3',
            'grand_total' => 'decimal:3',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @return HasMany<PurchaseReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class)->orderBy('display_order');
    }

    /**
     * @return HasMany<PurchaseReceiptCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(PurchaseReceiptCharge::class)->orderBy('display_order');
    }
}
