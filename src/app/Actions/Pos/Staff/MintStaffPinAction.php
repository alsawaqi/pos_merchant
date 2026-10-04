<?php

declare(strict_types=1);

namespace App\Actions\Pos\Staff;

use App\Enums\StaffStatus;
use App\Models\PosStaff;
use App\Support\ApproverVerifier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use RuntimeException;

/**
 * Mint a new 6-digit PIN that no other active or suspended staff member of
 * the company has, with its bcrypt hash and the LAUNCH-P5 offline verifier
 * ({@see ApproverVerifier}). Shared by hire and PIN reset.
 *
 * LAUNCH-P5 L3 — the uniqueness check runs under a lock: the company's
 * pos_companies row is locked FOR UPDATE first, so two hires (or a hire and
 * a reset) at the same moment are serialised and the second one sees the
 * first one's PIN. Callers must already be inside a DB transaction (the
 * lock lasts until it commits).
 */
final readonly class MintStaffPinAction
{
    /**
     * @return array{pin: string, hash: string, verifier: array{pin_offline_key: string, pin_offline_salt: string, pin_offline_iterations: int}}
     */
    public function handle(int $companyId, ?int $excludeStaffId = null): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Minting a PIN must run inside a transaction (it locks the company).');
        }

        $this->lockQuery($companyId)->value('id');

        // Hash::check across every non-terminated staff row in the company.
        // Terminated rows are soft-deleted, so the default scope filters them
        // out — a re-hire may reuse a PIN a former holder had.
        $existing = PosStaff::query()
            ->where('company_id', $companyId)
            ->when($excludeStaffId !== null, fn ($q) => $q->where('id', '!=', $excludeStaffId))
            ->whereIn('status', [
                StaffStatus::Active->value,
                StaffStatus::Suspended->value,
            ])
            ->pluck('pin_hash');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            // 6-digit numeric, leading zeros allowed (000000 → 999999).
            $candidate = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

            $collides = false;
            foreach ($existing as $hash) {
                if (Hash::check($candidate, $hash)) {
                    $collides = true;
                    break;
                }
            }

            if (! $collides) {
                return [
                    'pin' => $candidate,
                    'hash' => Hash::make($candidate),
                    'verifier' => ApproverVerifier::make($candidate),
                ];
            }
        }

        throw new RuntimeException(
            'Could not generate a unique PIN after 10 attempts. Either the keyspace is exhausted or the staff roster is too large for the current PIN length.',
        );
    }

    /** SELECT id FROM pos_companies WHERE id = ? FOR UPDATE (PostgreSQL). */
    public function lockQuery(int $companyId): Builder
    {
        return DB::table('pos_companies')->where('id', $companyId)->lockForUpdate();
    }
}
