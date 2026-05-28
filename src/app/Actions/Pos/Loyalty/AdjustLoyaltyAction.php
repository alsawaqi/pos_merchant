<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\User;

/**
 * Loyalty refactor — manual point/stamp adjustment by a portal
 * user. Thin wrapper over WriteLoyaltyTransactionAction with
 * type=adjust + a required reason. Replaces the Phase 6b
 * AdjustPointBalanceAction (and adds stamps).
 */
final readonly class AdjustLoyaltyAction
{
    public function __construct(
        private WriteLoyaltyTransactionAction $writeTransaction,
    ) {}

    public function handle(
        LoyaltyAccount $account,
        int $pointsDelta,
        int $stampsDelta,
        User $actor,
        string $reason,
    ): LoyaltyTransaction {
        return $this->writeTransaction->handle(
            $account,
            LoyaltyTransactionType::Adjust,
            $pointsDelta,
            $stampsDelta,
            $actor,
            $reason,
        );
    }
}
