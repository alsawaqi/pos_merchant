<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptPayment;
use App\Models\User;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AP — record one payment against a credit purchase receipt.
 *
 * Mirrors the loyalty ledger writer: locks the receipt row (SELECT … FOR
 * UPDATE) so concurrent partial payments can't both read the same outstanding
 * balance and over-pay, appends an immutable payment row carrying the balance
 * LEFT after it, then updates the receipt's denormalised amount_paid +
 * payment_status — all in one transaction so the receipt's amount_paid can
 * never drift from SUM(payments.amount).
 *
 * CRITICAL: a payment is a CASH-SETTLEMENT event, not a P&L event. The receipt
 * already booked its full GROSS cost as pos_expenses rows at receive time
 * (PD5/PD6 cash model), so net_profit already reflects the purchase. This
 * action therefore books NO expense — doing so would double-count the cost.
 *
 * Guards: the amount must be positive and may not exceed the still-owed
 * balance (a fully-paid receipt is rejected). When the payment settles the
 * receipt, payment_status flips to 'paid'.
 *
 * Audit event: purchase_receipt.payment.recorded.
 */
final readonly class WriteReceiptPaymentAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(
        PurchaseReceipt $receipt,
        float|int|string $amount,
        User $actor,
        ?string $method = null,
        ?string $note = null,
        ?DateTimeInterface $paidAt = null,
    ): PurchaseReceiptPayment {
        $companyId = $this->tenant->requiredId();
        if ((int) $receipt->company_id !== $companyId) {
            throw new RuntimeException('Purchase receipt does not belong to this company.');
        }

        $amount = (float) $amount;
        if ($amount <= 0) {
            throw new RuntimeException('A payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($receipt, $amount, $actor, $method, $note, $paidAt, $companyId): PurchaseReceiptPayment {
            /** @var PurchaseReceipt $locked */
            $locked = PurchaseReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            $grandTotal = (float) $locked->grand_total;
            $alreadyPaid = (float) $locked->amount_paid;
            $outstanding = $grandTotal - $alreadyPaid;

            if ($outstanding <= 1e-9) {
                throw new RuntimeException('This receipt is already fully paid.');
            }
            if ($amount > $outstanding + 1e-9) {
                throw new RuntimeException('The payment exceeds the outstanding balance of '.number_format($outstanding, 3, '.', '').'.');
            }

            $newPaid = $alreadyPaid + $amount;
            $balanceAfter = $grandTotal - $newPaid;
            if ($balanceAfter < 0) {
                $balanceAfter = 0.0;
            }
            $paymentStatus = $newPaid >= $grandTotal - 1e-9 ? 'paid' : 'partial';

            /** @var PurchaseReceiptPayment $payment */
            $payment = PurchaseReceiptPayment::query()->create([
                'company_id' => $companyId,
                'purchase_receipt_id' => $locked->id,
                'amount' => number_format($amount, 3, '.', ''),
                'balance_after' => number_format($balanceAfter, 3, '.', ''),
                'method' => $method,
                'note' => $note,
                'recorded_by_user_id' => (int) $actor->getKey(),
                'paid_at' => $paidAt ?? now(),
            ]);

            $locked->update([
                'amount_paid' => number_format($newPaid, 3, '.', ''),
                'payment_status' => $paymentStatus,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'purchase_receipt.payment.recorded',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: PurchaseReceiptPayment::class,
                auditableId: $payment->id,
                newValues: [
                    'amount' => number_format($amount, 3, '.', ''),
                    'balance_after' => number_format($balanceAfter, 3, '.', ''),
                    'payment_status' => $paymentStatus,
                ],
            ));

            return $payment->fresh();
        });
    }
}
