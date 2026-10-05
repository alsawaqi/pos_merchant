<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * LAUNCH review add-on (D2) — one container row of a day-end count line
 * ("3 × bottle 1.5 l + 5 × bottle 500 ml"), with the container's label and
 * size as they stood at the count.
 *
 * Schema owned by pos_admin (2026_10_06_100008).
 */
#[Fillable([
    'stock_count_line_id',
    'company_id',
    'container_id',
    'container_label',
    'container_factor',
    'pieces',
])]
class StockCountLineContainer extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_stock_count_line_containers';
}
