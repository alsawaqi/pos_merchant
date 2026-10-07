<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH combo add-on — an upgrade of a FIXED combo / meal line: a real
 * product (sold on its own too) the customer may swap to, at upgrade_price
 * (>= 0). UNIQUE (line_id, product_id); the product FK is RESTRICT.
 */
#[Fillable(['company_id', 'line_id', 'product_id', 'upgrade_price', 'sort_order'])]
class ComboLineUpgrade extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_combo_line_upgrades';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['upgrade_price' => 'decimal:3', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
