<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory\Concerns;

use Illuminate\Validation\Validator;

/**
 * PD5 — the cash-model purchase-cost rule shared by every "buy / receive new
 * stock" request. A positive total_cost is REQUIRED so the buy books a
 * categorized expense — UNLESS the user explicitly ticks `no_cost` (a free
 * sample, a count correction, an inter-company move). delivery_cost is the
 * optional logistics charge booked separately. Both money fields cap at the
 * decimal(12,3) bound.
 */
trait RequiresPurchaseCost
{
    /**
     * @return array<string, mixed>
     */
    protected function purchaseCostRules(): array
    {
        return [
            'total_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'no_cost' => ['nullable', 'boolean'],
        ];
    }

    protected function afterPurchaseCost(Validator $validator): void
    {
        $positive = static function (mixed $v): bool {
            return $v !== null && $v !== '' && (float) $v > 0;
        };

        if ($this->boolean('no_cost')) {
            // Enforce the "No cost" contract server-side (not just in the UI):
            // a free / correction receive must carry no money at all, or it
            // would still book an expense via the actions' cost > 0 checks.
            if ($positive($this->input('total_cost')) || $positive($this->input('delivery_cost'))) {
                $validator->errors()->add(
                    'no_cost',
                    'Untick "No cost" to enter a cost, or clear the cost and delivery to record a free receive.',
                );
            }

            return;
        }

        if (! $positive($this->input('total_cost'))) {
            $validator->errors()->add(
                'total_cost',
                'Enter the cost paid for this stock, or tick "No cost" for a free sample / correction.',
            );
        }
    }
}
