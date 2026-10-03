<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH-P4 — "sold out" at one branch (owner decision 4): a row present means
 * the product is sold out there, on every channel, until someone switches it
 * back on (the row is deleted). Manual only — never driven by stock. UNIQUE
 * (branch_id, product_id).
 */
#[Fillable([
    'company_id',
    'branch_id',
    'product_id',
    'set_by_user_id',
    'set_by_pos_staff_id',
    'set_at',
])]
class ProductSoldOut extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_product_sold_out';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'set_at' => 'datetime',
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
}
