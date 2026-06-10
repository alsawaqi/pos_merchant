<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AddOnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase 4.9 — an individual selectable option under an
 * {@see AddOnGroup} ("Whole milk", "Oat milk", "Extra shot").
 *
 * price_delta is added to the product's base price when this
 * add-on is selected. Can be 0 for free options. Stored as
 * decimal(12,3) for OMR baisa precision — same as base_price
 * so client-side math stays consistent.
 *
 * ingredient_id / qty / unit are Phase 5 placeholders. Once
 * pos_ingredients exists, an "Extra shot" add-on will deduct
 * the configured amount of espresso beans on sale. Until then
 * those columns stay NULL and the FK constraint isn't enforced.
 */
#[Fillable([
    'uuid',
    'company_id',
    'add_on_group_id',
    'name',
    'name_ar',
    'price_delta',
    'is_default',
    'ingredient_id',
    'ingredient_qty',
    'ingredient_unit',
    'display_order',
    'status',
])]
class AddOn extends Model
{
    /** @use HasFactory<AddOnFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_addons';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:3',
            // Phase B — pre-selected in the POS customize sheet.
            'is_default' => 'boolean',
            'ingredient_qty' => 'decimal:3',
            'display_order' => 'integer',
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
     * @return BelongsTo<AddOnGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AddOnGroup::class, 'add_on_group_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
