<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Pos\Inventory\TransferStockAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A stock transfer between two branches (§5.6).
 *
 * Header for an immediate, atomic move: each line wrote a paired transfer_out
 * (from_branch) + transfer_in (to_branch) stock movement at create time. There
 * is no status lifecycle — recording the transfer IS the move.
 *
 * Schema owned by pos_admin (2026_06_13_010000). pos_merchant writes only
 * through {@see TransferStockAction}.
 */
#[Fillable([
    'uuid',
    'company_id',
    'from_branch_id',
    'to_branch_id',
    'transferred_by_user_id',
    'transferred_at',
    'note',
])]
class BranchTransfer extends Model
{
    protected $table = 'pos_branch_transfers';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
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
     * @return BelongsTo<Branch, $this>
     */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by_user_id');
    }

    /**
     * @return HasMany<BranchTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BranchTransferLine::class)->orderBy('id');
    }
}
