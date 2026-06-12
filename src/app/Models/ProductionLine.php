<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P-G1 — one ingredient line of a kitchen production batch
 * (pos_production_lines). is_extra=false rows are the LOCKED recipe x
 * batch quantity; is_extra=true rows are the extras the chef declared.
 * Stored separately so the merchant gets the kitchen variance view
 * (what recipes say vs what the kitchen actually uses).
 *
 * Written exclusively by pos_api; read-only here.
 */
#[Fillable([])]
class ProductionLine extends Model
{
    protected $table = 'pos_production_lines';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'is_extra' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Production, $this>
     */
    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
