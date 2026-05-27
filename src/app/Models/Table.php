<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use Database\Factories\TableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Individual seating spots within a {@see Floor}. Phase 5.
 *
 * The `qr_token` column is auto-minted on create + globally
 * unique. Customers scan a printed QR card to open
 * /menu?t={qr_token} on their phone — the token never leaks
 * the internal id, and {@see \App\Actions\Pos\FloorPlan\RegenerateTableQrAction}
 * lets the merchant invalidate a lost / stolen card by
 * rolling the token.
 */
#[Fillable([
    'uuid',
    'company_id',
    'floor_id',
    'label',
    'seats',
    'min_party',
    'max_party',
    'shape',
    'notes',
    'qr_token',
    'status',
    'display_order',
    // Phase 5.5 — visual floor planner. NULL on these means
    // "not placed yet" (frontend auto-arranges in a grid)
    // or "use shape default size".
    'position_x',
    'position_y',
    'width',
    'height',
])]
class Table extends Model
{
    /** @use HasFactory<TableFactory> */
    use HasFactory, SoftDeletes;

    /**
     * `tables` clashes with Eloquent's $table property name in
     * the source code (the class doc, not behavior), and SQL
     * keyword sensitivities vary by driver. `pos_tables` is
     * Postgres-safe — Laravel quotes the identifier on every
     * query.
     */
    protected $table = 'pos_tables';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'min_party' => 'integer',
            'max_party' => 'integer',
            'display_order' => 'integer',
            'status' => TableStatus::class,
            'shape' => TableShape::class,
            // Phase 5.5 — pixel positions. Cast so they come
            // back as ints (not strings from the DB driver),
            // and survive a JSON round-trip without quoting.
            'position_x' => 'integer',
            'position_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if ($row->uuid === null || $row->uuid === '') {
                $row->uuid = (string) Str::uuid();
            }
            if ($row->qr_token === null || $row->qr_token === '') {
                $row->qr_token = self::mintQrToken();
            }
        });
    }

    /**
     * 24-char URL-safe random token. Globally unique across
     * pos_tables (DB UNIQUE) — on the astronomically tiny
     * chance of collision we let the DB-level violation bubble
     * up and the action retries.
     */
    public static function mintQrToken(): string
    {
        return Str::random(24);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Floor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TableStatus::Active->value);
    }
}
