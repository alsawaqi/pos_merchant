<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 *
 * Default: a successful 5.000 OMR cash payment. Caller MUST
 * pass ->for($order, 'order') so SUM(payments WHERE
 * status=success) lines up with the parent's grand_total
 * for paid-order tests.
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'order_id' => Order::factory(),
            'method' => PaymentMethod::Cash->value,
            'amount' => '5.000',
            'change_given' => null,
            'softpos_reference' => null,
            'softpos_auth_code' => null,
            'status' => PaymentStatus::Success->value,
            'pending_reconciliation' => false,
            'reconciled_by_admin_id' => null,
            'reconciled_at' => null,
            'captured_at' => now(),
        ];
    }

    public function card(): static
    {
        return $this->state(fn (): array => [
            'method' => PaymentMethod::Card->value,
            'softpos_reference' => 'TXN_' . strtoupper(Str::random(10)),
            'softpos_auth_code' => strtoupper(Str::random(6)),
        ]);
    }

    public function pendingReconciliation(): static
    {
        return $this->state(fn (): array => [
            'method' => PaymentMethod::Card->value,
            'status' => PaymentStatus::PendingReconciliation->value,
            'pending_reconciliation' => true,
            'softpos_reference' => null,
            'softpos_auth_code' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed->value,
        ]);
    }
}
