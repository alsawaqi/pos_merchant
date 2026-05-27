<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientUnit;
use Database\Factories\RestockRequestLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5c — a single line in a restock request.
 *
 * Composite-unique on (restock_request_id, ingredient_id) —
 * an ingredient appears at most once per request. The Action
 * layer is the only legitimate writer; merging duplicate
 * requested lines is the caller's responsibility.
 *
 * quantity_allocated starts at 0; AllocateRestockRequestAction
 * sets it to the actually-shipped quantity (which may be less
 * than requested in the partial-fulfillment case). The
 * stock_movement written for this line carries the same
 * allocated value as a positive Restock movement.
 *
 * Schema owned by pos_admin's 2026_05_31_010200 migration.
 */
#[Fillable([
    'restock_request_id',
    'ingredient_id',
    'quantity_requested',
    'quantity_allocated',
    'unit_at_set',
    'note',
    'sort_order',
])]
class RestockRequestLine extends Model
{
    /** @use HasFactory<RestockRequestLineFactory> */
    use HasFactory;

    protected $table = 'pos_restock_request_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:3',
            'quantity_allocated' => 'decimal:3',
            'unit_at_set' => IngredientUnit::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RestockRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RestockRequest::class, 'restock_request_id');
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
