<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH-P4 — one item a combo slot offers, with its extra price (the same on
 * every channel) and whether it is pre-selected. The item must be a standard
 * product of the same company (app-enforced; pos:check-tenant-integrity
 * checks it). UNIQUE (slot_id, product_id); product FK is RESTRICT.
 */
#[Fillable([
    'company_id',
    'slot_id',
    'product_id',
    'extra_price',
    'is_default',
    'sort_order',
])]
class ComboSlotOption extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_combo_slot_options';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extra_price' => 'decimal:3',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ComboSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(ComboSlot::class, 'slot_id');
    }

    /**
     * The chosen item. withTrashed: a deleted option product still names
     * the option in the editor until the merchant removes it.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
