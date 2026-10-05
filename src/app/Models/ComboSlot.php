<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * LAUNCH-P4 — one choice slot of a combo ("Main", "Side", "Drink"): the
 * customer picks between min_choices and max_choices items from its options.
 * A combo is a pos_products row with product_type='combo'.
 *
 * Schema owned by pos_admin (LAUNCH-P4 data contract): CHECK min_choices >= 0
 * AND max_choices >= 1 AND max_choices >= min_choices.
 */
#[Fillable([
    'uuid',
    'company_id',
    'combo_product_id',
    'name',
    'name_ar',
    'min_choices',
    'max_choices',
    'sort_order',
    // LAUNCH review add-on — the slot offered as "Make it a meal?" (at
    // most one per combo, only on a slot with min = max = 1).
    'is_main',
])]
class ComboSlot extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_combo_slots';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_choices' => 'integer',
            'max_choices' => 'integer',
            'sort_order' => 'integer',
            'is_main' => 'boolean',
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

    /**
     * @return BelongsTo<Product, $this>
     */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'combo_product_id');
    }

    /**
     * @return HasMany<ComboSlotOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ComboSlotOption::class, 'slot_id')->orderBy('sort_order')->orderBy('id');
    }
}
