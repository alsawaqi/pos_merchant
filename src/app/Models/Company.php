<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The merchant company this portal user belongs to. Schema is owned
 * by pos_admin; this app reads only.
 *
 * Only the fields the merchant portal needs to render are exposed;
 * pos_admin's CompanyDetailResource is the canonical projection.
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_companies';

    // We never CREATE or UPDATE companies from this app — that's a
    // pos_admin-only responsibility. Empty fillable + explicit guard
    // keeps any accidental mass-assignment here from mutating the
    // shared table.
    protected $fillable = [];
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cr_issue_date' => 'date',
            'cr_expiry_date' => 'date',
            'establishment_date' => 'date',
            'vat_registered_at' => 'date',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function portalUsers(): HasMany
    {
        return $this->hasMany(User::class)->where('user_type', 'merchant');
    }
}
