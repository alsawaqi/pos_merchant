<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * P-G6 — a portal → portal inbox message (channel 2). target_type =
 * user | role | branch:
 *
 *   user    one specific portal user;
 *   role    every user holding the spatie role NAME under the company
 *           team (e.g. all Branch Managers);
 *   branch  everyone whose F5 branch scope includes the branch
 *           (unrestricted users see every branch's messages).
 *
 * Recipients resolve AT READ TIME (see {@see scopeVisibleTo}) — a user
 * added to a role or scope later still sees the history. One-way v1.
 */
#[Fillable([
    'uuid',
    'company_id',
    'sender_user_id',
    'target_type',
    'target_user_id',
    'target_role',
    'target_branch_id',
    'subject',
    'body',
])]
class PortalMessage extends Model
{
    public const TARGET_USER = 'user';

    public const TARGET_ROLE = 'role';

    public const TARGET_BRANCH = 'branch';

    protected $table = 'pos_portal_messages';

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            if (blank($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The inbox-visibility predicate for [$user]: their own targets, their
     * role groups, and branch audiences within their F5 scope.
     *
     * @param  Builder<self>  $query
     * @param  list<string>  $roleNames  the user's role names under the company team
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user, array $roleNames): Builder
    {
        $allowed = $user->allowedBranchIds();

        return $query->where(function (Builder $q) use ($user, $roleNames, $allowed): void {
            $q->where(function (Builder $w) use ($user): void {
                $w->where('target_type', self::TARGET_USER)
                    ->where('target_user_id', $user->getKey());
            })->orWhere(function (Builder $w) use ($roleNames): void {
                $w->where('target_type', self::TARGET_ROLE)
                    ->whereIn('target_role', $roleNames ?: ['__none__']);
            })->orWhere(function (Builder $w) use ($allowed): void {
                $w->where('target_type', self::TARGET_BRANCH);
                if ($allowed !== null) {
                    $w->whereIn('target_branch_id', $allowed ?: [0]);
                }
            });
        })->where(function (Builder $q) use ($user): void {
            // A sender's role/branch sends would otherwise land back in
            // their OWN inbox as unread (a branch send is always within the
            // sender's scope). The sent tab is where their copy lives.
            $q->whereNull('sender_user_id')
                ->orWhere('sender_user_id', '!=', $user->getKey());
        });
    }

    /**
     * @return HasMany<PortalMessageRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(PortalMessageRead::class, 'portal_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
    }
}
