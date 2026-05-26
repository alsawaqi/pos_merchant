<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Request-scoped tenant context for the merchant portal.
 *
 * Unlike pos_admin's TenantContext (which holds the platform-team
 * sentinel and a per-request company id when admins drill into a
 * merchant), every authenticated pos_merchant request is
 * implicitly pinned to the signed-in user's company_id — merchants
 * only ever see their own data. The middleware sets this on every
 * authenticated request; everything downstream just reads
 * {@see self::id()}.
 */
final class MerchantTenantContext
{
    private ?int $companyId = null;

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    /**
     * Demands a tenant id — throws if not set. Use this in
     * controllers/actions that absolutely require the company
     * scope (vs read it nullable like {@see id()}).
     */
    public function requiredId(): int
    {
        if ($this->companyId === null) {
            throw new \RuntimeException('No merchant tenant scope set for this request.');
        }

        return $this->companyId;
    }
}
