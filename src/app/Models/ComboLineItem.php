<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH combo add-on — a CHOICE line's override for one product of its
 * category: unticked (excluded) and / or an extra price (>= 0). A category
 * product without a row is in, free. UNIQUE (line_id, product_id).
 */
#[Fillable(['company_id', 'line_id', 'product_id', 'excluded', 'extra_price'])]
class ComboLineItem extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_combo_line_items';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['excluded' => 'boolean', 'extra_price' => 'decimal:3'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
