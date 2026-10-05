<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * LAUNCH review add-on (D1) — one container row of a branch transfer line
 * ("3 × bottle 1.5 l"), with the container's label and size as they stood
 * when it moved. The line keeps one row per ingredient.
 *
 * Schema owned by pos_admin (2026_10_06_100008).
 */
#[Fillable([
    'branch_transfer_line_id',
    'company_id',
    'container_id',
    'container_label',
    'container_factor',
    'pieces',
])]
class BranchTransferLineContainer extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_branch_transfer_line_containers';
}
