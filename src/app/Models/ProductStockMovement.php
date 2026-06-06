<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 — append-only ledger row for UNIT (finished-good) PRODUCT stock.
 * branch_id NULL = the central company pool; set = a branch's count. Schema
 * owned by pos_admin (2026_06_25_010200_create_pos_product_stock_movements_table).
 *
 * occurred_at + created_at only (no updated_at — append-only), so Eloquent's
 * timestamp pair is disabled and created_at is set explicitly by the writer.
 */
#[Fillable([
    'company_id',
    'product_id',
    'branch_id',
    'movement_type',
    'quantity',
    'reference_type',
    'reference_id',
    'recorded_by_user_id',
    'recorded_by_pos_staff_id',
    'note',
    'occurred_at',
    'created_at',
])]
class ProductStockMovement extends Model
{
    protected $table = 'pos_product_stock_movements';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'movement_type' => ProductStockMovementType::class,
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
