<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Expense>
 *
 * Default: a 5.000 OMR "supplies" expense in the "recorded"
 * state, no logger set. Caller passes ->for($company, 'company')
 * and ->for($branch, 'branch') for tenant consistency, plus the
 * logged_by_* FK as appropriate.
 */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'category' => ExpenseCategory::Supplies->value,
            'amount' => '5.000',
            'note' => null,
            'receipt_photo_path' => null,
            'logged_by_pos_staff_id' => null,
            'logged_by_portal_user_id' => null,
            'logged_at' => now(),
            'status' => ExpenseStatus::Recorded->value,
            'reviewed_by_portal_user_id' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ];
    }

    public function reviewed(): static
    {
        return $this->state(fn (): array => [
            'status' => ExpenseStatus::Reviewed->value,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ExpenseStatus::Rejected->value,
            'reviewed_at' => now(),
            'review_note' => 'Not a valid business expense.',
        ]);
    }

    public function category(ExpenseCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category->value]);
    }
}
