<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * Read-only mirror of pos_admin's Device. Schema owned by
 * pos_admin's migrations; pos_merchant only reads — except
 * for Sanctum PAT issuance, which writes to the polymorphic
 * `personal_access_tokens` table (not to `pos_devices`).
 *
 * The HasApiTokens trait is what lets the Android cashier app
 * call `auth:sanctum` endpoints. Every device gets a long-lived
 * Personal Access Token at activation time
 * ({@see \App\Actions\Pos\Android\ConsumeActivationTokenAction})
 * and carries it as `Authorization: Bearer <token>` for all
 * subsequent calls. The `ResolveDeviceTenantContext` middleware
 * loads the Device from `$request->user()` and pins
 * MerchantTenantContext to its branch's company.
 *
 * $guarded = ['*'] keeps any mass-assignment surprise off the
 * shared row; the merchant app should never mutate device
 * config — that's pos_admin's job.
 */
#[Fillable([])]
class Device extends Model
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'pos_devices';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * One-shot activation tokens minted by pos_admin. The
     * merchant-side activation flow looks tokens up by
     * sha256(plain) and stamps used_at on consume.
     *
     * @return HasMany<DeviceActivationToken, $this>
     */
    public function activationTokens(): HasMany
    {
        return $this->hasMany(DeviceActivationToken::class);
    }

    /**
     * True when the device can be used for POS operations
     * right now. Decommissioned, suspended, or unassigned
     * devices return false — used by the Sanctum middleware
     * to reject Bearer tokens at the edge.
     */
    public function isOperable(): bool
    {
        return ! $this->trashed()
            && in_array($this->status, ['assigned', 'active'], true)
            && $this->branch_id !== null
            && $this->company_id !== null;
    }
}
