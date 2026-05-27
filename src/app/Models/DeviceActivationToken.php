<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_admin's DeviceActivationToken. The
 * Android POS posts a plaintext code to
 * /api/devices/activate — we sha256 it, look it up here,
 * verify expires_at + used_at + revoked_at, then issue a
 * Sanctum PAT for the linked Device.
 *
 * Two columns are merchant-writable in this flow:
 *   - used_at: stamped exactly once on successful consume
 *     (we'd lock the row in the transaction first to make
 *     the single-use guarantee atomic).
 *
 * Everything else (token_hash, expires_at, revoked_at) is
 * owned by pos_admin. Mirror the Fillable narrow accordingly.
 */
#[Fillable(['used_at'])]
class DeviceActivationToken extends Model
{
    protected $table = 'pos_device_activation_tokens';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
