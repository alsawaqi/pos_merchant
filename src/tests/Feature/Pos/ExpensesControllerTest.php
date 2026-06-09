<?php

declare(strict_types=1);

/**
 * Phase 6 backfill — Expenses (§5.10) controller coverage.
 *
 * Covers:
 *   - Permission gating (view vs manage, role-less 403)
 *   - Log (store) + validation + branch tenancy
 *   - Review (approve) lifecycle + optional annotation
 *   - Reject lifecycle + required reason
 *   - Illegal transitions (review/reject a rejected expense)
 *   - List filters (status / category / branch / date window)
 *   - Tenant isolation (foreign expense 404)
 *   - Audit rows for log / review / reject
 */

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== PERMISSION GATING ===================

it('forbids the index to a role without expenses.view', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/expenses')->assertForbidden();
});

it('lets a Viewer list expenses but forbids logging one', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/expenses')->assertOk();
    $this->postJson('/api/expenses', [
        'branch_id' => 1,
        'category' => ExpenseCategory::Utilities->value,
        'amount' => '12.500',
    ])->assertForbidden();
});

// =================== LOG (STORE) ===================

it('logs an expense from the portal and writes an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/expenses', [
        'branch_id' => $ctx['branch']->id,
        'category' => ExpenseCategory::Utilities->value,
        'amount' => '12.500',
        'note' => 'Electricity bill',
    ])->assertStatus(201);

    expect($response->json('data.category'))->toBe('utilities');
    expect($response->json('data.amount'))->toBe('12.500');
    expect($response->json('data.status'))->toBe('recorded');
    expect($response->json('data.logged_by_portal_user_id'))->toBe($ctx['user']->id);

    $this->assertDatabaseHas('pos_expenses', [
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'category' => 'utilities',
        'amount' => '12.500',
        'status' => 'recorded',
        'logged_by_portal_user_id' => $ctx['user']->id,
    ]);
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'expense.logged',
        'company_id' => $ctx['company']->id,
    ]);
});

it('logs a general (no-branch) expense', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/expenses', [
        'branch_id' => null,
        'category' => ExpenseCategory::Other->value,
        'amount' => '40.000',
        'note' => 'Head-office internet',
    ])->assertStatus(201);

    expect($response->json('data.branch_id'))->toBeNull();
    $this->assertDatabaseHas('pos_expenses', [
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'category' => 'other',
        'amount' => '40.000',
    ]);
});

it('returns 422 on missing/invalid fields', function (): void {
    $ctx = makeMerchantActor();

    $this->postJson('/api/expenses', [
        'branch_id' => $ctx['branch']->id,
        'category' => ExpenseCategory::Other->value,
        // amount missing
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

    // v2 #7: an unknown category is now rejected in LogExpenseAction (a
    // per-company key lookup), so it surfaces as a {message} 422 rather than
    // a FormRequest validation error keyed 'category'.
    $unknownCat = $this->postJson('/api/expenses', [
        'branch_id' => $ctx['branch']->id,
        'category' => 'not-a-category',
        'amount' => '5.000',
    ])->assertStatus(422);
    expect($unknownCat->json('message'))->toContain('category');

    $this->postJson('/api/expenses', [
        'branch_id' => $ctx['branch']->id,
        'category' => ExpenseCategory::Other->value,
        'amount' => '0',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

it('refuses to log against a branch from another company', function (): void {
    makeMerchantActor();
    $foreign = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($foreign, 'company')->create();

    $this->postJson('/api/expenses', [
        'branch_id' => $foreignBranch->id,
        'category' => ExpenseCategory::Supplies->value,
        'amount' => '5.000',
    ])->assertStatus(422);
});

// =================== LIST + FILTERS ===================

it('lists expenses scoped to the tenant, newest first', function (): void {
    $ctx = makeMerchantActor();
    Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'category' => ExpenseCategory::Supplies->value,
        'logged_at' => '2026-06-01 09:00:00',
    ]);
    Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'category' => ExpenseCategory::Maintenance->value,
        'logged_at' => '2026-06-10 09:00:00',
    ]);

    // A foreign company's expense must not leak.
    $foreign = Company::factory()->create();
    $fb = Branch::factory()->for($foreign, 'company')->create();
    Expense::factory()->for($foreign, 'company')->for($fb, 'branch')->create();

    $response = $this->getJson('/api/expenses')->assertOk();
    $rows = $response->json('data');
    expect($rows)->toHaveCount(2);
    expect($rows[0]['category'])->toBe('maintenance'); // newest first
});

it('filters by status, category, and branch', function (): void {
    $ctx = makeMerchantActor();
    $other = Branch::factory()->for($ctx['company'], 'company')->create();

    Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'category' => ExpenseCategory::Utilities->value,
        'status' => ExpenseStatus::Recorded->value,
    ]);
    Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->reviewed()->create([
        'category' => ExpenseCategory::Salaries->value,
    ]);
    Expense::factory()->for($ctx['company'], 'company')->for($other, 'branch')->create([
        'category' => ExpenseCategory::Utilities->value,
    ]);

    expect($this->getJson('/api/expenses?status=recorded')->assertOk()->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/expenses?status=reviewed')->assertOk()->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/expenses?category=utilities')->assertOk()->json('data'))->toHaveCount(2);
    expect($this->getJson("/api/expenses?branch_id={$other->id}")->assertOk()->json('data'))->toHaveCount(1);
    // Fail-closed on an unknown status.
    expect($this->getJson('/api/expenses?status=bogus')->assertOk()->json('data'))->toHaveCount(0);
});

// =================== REVIEW ===================

it('reviews (approves) a recorded expense with an annotation', function (): void {
    $ctx = makeMerchantActor();
    $expense = Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();

    $response = $this->postJson("/api/expenses/{$expense->uuid}/review", [
        'review_note' => 'Confirmed against the receipt.',
    ])->assertOk();

    expect($response->json('data.status'))->toBe('reviewed');
    expect($response->json('data.review_note'))->toBe('Confirmed against the receipt.');
    expect($response->json('data.reviewed_by_portal_user_id'))->toBe($ctx['user']->id);

    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'expense.reviewed']);
});

// =================== REJECT ===================

it('rejects an expense with a required reason', function (): void {
    $ctx = makeMerchantActor();
    $expense = Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();

    // Missing reason → 422.
    $this->postJson("/api/expenses/{$expense->uuid}/reject", [])
        ->assertStatus(422)->assertJsonValidationErrors(['review_note']);

    $response = $this->postJson("/api/expenses/{$expense->uuid}/reject", [
        'review_note' => 'Duplicate of expense #12.',
    ])->assertOk();

    expect($response->json('data.status'))->toBe('rejected');
    expect($response->json('data.review_note'))->toBe('Duplicate of expense #12.');
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'expense.rejected']);
});

it('refuses to review a rejected expense', function (): void {
    $ctx = makeMerchantActor();
    $expense = Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->rejected()->create();

    $this->postJson("/api/expenses/{$expense->uuid}/review", [])->assertStatus(422);
});

it('refuses to reject an already-rejected expense', function (): void {
    $ctx = makeMerchantActor();
    $expense = Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->rejected()->create();

    $this->postJson("/api/expenses/{$expense->uuid}/reject", [
        'review_note' => 'Again',
    ])->assertStatus(422);
});

// =================== TENANT ISOLATION ===================

it('returns 404 when reviewing an expense from another company', function (): void {
    makeMerchantActor();
    $foreign = Company::factory()->create();
    $fb = Branch::factory()->for($foreign, 'company')->create();
    $foreignExpense = Expense::factory()->for($foreign, 'company')->for($fb, 'branch')->create();

    $this->postJson("/api/expenses/{$foreignExpense->uuid}/review", [])->assertNotFound();
    $this->postJson("/api/expenses/{$foreignExpense->uuid}/reject", [
        'review_note' => 'x',
    ])->assertNotFound();
});
