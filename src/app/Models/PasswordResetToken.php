<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Self-service forgot-password token (Phase D7).
 *
 * Lives in the shared `pos_password_reset_tokens` table (schema
 * owned by pos_admin's migrations). Deliberately NOT Laravel's
 * Password-broker table: the live shared DB already has an
 * email-keyed `password_reset_tokens` that belongs to the charity
 * app, and the broker cannot scope by user_type — this table is
 * user_id-keyed so each portal's lookup stays inside its own user
 * population.
 *
 * Only the SHA-256 hash of the token is stored; the raw value
 * exists solely in the reset email. `used_at` makes a token
 * single-use even inside its expiry window.
 */
#[Fillable([
    'user_id',
    'token_hash',
    'expires_at',
    'used_at',
    'created_at',
])]
class PasswordResetToken extends Model
{
    protected $table = 'pos_password_reset_tokens';

    /** Append-ish row — created_at is set explicitly, no updated_at. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
