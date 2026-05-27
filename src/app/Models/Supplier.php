<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase 5a — per-merchant supplier directory.
 *
 * Soft delete preserves historical ingredient references when a
 * supplier is retired but old stock movements still mention
 * them. DeleteSupplierAction refuses while any non-deleted
 * ingredient still names this supplier as primary.
 *
 * Schema owned by pos_admin's 2026_05_29_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'contact',
    'notes',
    'status',
])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_suppliers';

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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Ingredients that name this supplier as primary. Used by
     * DeleteSupplierAction to guard hard-deletion.
     *
     * @return HasMany<Ingredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'primary_supplier_id');
    }
}
