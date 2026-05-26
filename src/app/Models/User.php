<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Shared with pos_admin via the `pos_users` table.
 *
 * Platform admins (user_type='platform_admin') and merchant portal
 * users (user_type='merchant') live in the same table. This app
 * cares about merchant rows; the {@see scopeMerchant()} helper +
 * the EnsureUserIsMerchant middleware enforce the split so a
 * platform-admin password never opens the merchant portal.
 *
 * Identical column shape to pos_admin's User — keep both in sync.
 * Schema is owned by pos_admin's migrations; this app only reads.
 */
#[Fillable([
    'company_id',
    'name',
    'email',
    'phone',
    'password',
    'user_type',
    'status',
    'last_login_at',
    'timezone',
    'locale',
    'metadata',
    'setup_token_hash',
    'setup_token_expires_at',
    'branch_scope_json',
    'invited_at',
    'invited_by_admin_id',
])]
#[Hidden(['password', 'remember_token', 'setup_token_hash'])]
class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    protected $table = 'pos_users';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'metadata' => 'array',
            'setup_token_expires_at' => 'datetime',
            'branch_scope_json' => 'array',
            'invited_at' => 'datetime',
            // PII at rest — phone is encrypted in the shared table
            // (pos_admin Sprint 3). The cast here keeps reads
            // working transparently.
            'phone' => 'encrypted',
        ];
    }

    /**
     * Belongs to the merchant company that owns this portal user.
     * Always populated for user_type='merchant'; NULL for platform
     * admin rows.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Restrict any query to merchant rows. Used by the login attempt
     * + every controller that queries this table so a platform-admin
     * id can never be returned by a merchant endpoint.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeMerchant(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('user_type', 'merchant');
    }

    /**
     * True for merchant portal users (this app's only legitimate
     * user_type). The login flow checks this and rejects platform
     * admin credentials before issuing a session.
     */
    public function isMerchantUser(): bool
    {
        return $this->user_type === 'merchant';
    }
}
