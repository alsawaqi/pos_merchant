<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountTargetType;
use Database\Factories\DiscountTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6d — discount target row (blueprint §10.7).
 *
 * Polymorphic pivot from a discount to either a product or a
 * category. No FK on target_id (the column points to one of
 * two tables); tenant consistency enforced by the Action layer.
 *
 * Schema owned by pos_admin's 2026_06_05_010100 migration.
 */
#[Fillable([
    'discount_id',
    'target_type',
    'target_id',
])]
class DiscountTarget extends Model
{
    /** @use HasFactory<DiscountTargetFactory> */
    use HasFactory;

    protected $table = 'pos_discount_targets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => DiscountTargetType::class,
            'target_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
