<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-branch product availability + unit stock (pivot pos_branch_product).
 *
 * Owned by pos_admin's shared schema; the merchant manages it from the
 * product editor (which branches sell a product + how many units each
 * holds). NULL stock_qty = not unit-tracked at that branch.
 */
class BranchProduct extends Model
{
    protected $table = 'pos_branch_product';

    /** @var list<string> */
    protected $fillable = [
        'branch_id',
        'product_id',
        'is_available',
        'stock_qty',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'stock_qty' => 'decimal:3',
        ];
    }
}
