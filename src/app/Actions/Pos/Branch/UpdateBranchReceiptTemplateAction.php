<?php

declare(strict_types=1);

namespace App\Actions\Pos\Branch;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Merchant-side write of one branch's custom receipt template (the
 * header/footer the POS device prints). Stored on
 * pos_branches.receipt_template and shipped to the device via
 * /device/config → SunmiReceiptService.
 *
 * The validated payload is normalized into the canonical stored
 * shape so the device always sees a predictable object: trimmed
 * strings (empty → null), de-blanked line arrays, and the two
 * boolean toggles defaulted. A no-op save writes no audit row.
 *
 * Audit event: `branch.receipt_template.updated`.
 */
final readonly class UpdateBranchReceiptTemplateAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Branch $branch, array $attributes, User $actor): Branch
    {
        $companyId = $this->tenant->requiredId();

        // Defence in depth on top of the controller's tenant guard.
        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($branch, $attributes, $actor, $companyId): Branch {
            $old = $branch->receipt_template;
            $template = $this->normalize($attributes);

            // No-op (same template round-tripped) — don't tax the audit log.
            if (json_encode($old) === json_encode($template)) {
                return $branch->fresh();
            }

            $branch->receipt_template = $template;
            $branch->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'branch.receipt_template.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: Branch::class,
                auditableId: $branch->id,
                oldValues: ['receipt_template' => $old],
                newValues: ['receipt_template' => $template],
            ));

            return $branch->fresh();
        });
    }

    /**
     * Normalize the validated payload into the canonical stored shape.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function normalize(array $a): array
    {
        $str = static function (string $key) use ($a): ?string {
            $v = $a[$key] ?? null;

            return is_string($v) && trim($v) !== '' ? trim($v) : null;
        };

        $lines = static function (string $key) use ($a): array {
            $raw = is_array($a[$key] ?? null) ? $a[$key] : [];

            return array_values(array_filter(
                array_map(static fn ($v): string => trim((string) $v), $raw),
                static fn (string $v): bool => $v !== '',
            ));
        };

        return [
            'business_name' => $str('business_name'),
            'business_name_ar' => $str('business_name_ar'),
            'cr_number' => $str('cr_number'),
            'vat_number' => $str('vat_number'),
            'address' => $str('address'),
            'phone' => $str('phone'),
            'header_lines' => $lines('header_lines'),
            'footer_lines' => $lines('footer_lines'),
            'show_qr' => (bool) ($a['show_qr'] ?? true),
            'logo_base64' => $this->logo($a['logo_base64'] ?? null),
        ];
    }

    /**
     * Keep the logo only when it's a valid base64-encoded PNG; anything else
     * (null, blank, non-PNG) stores as null. Defence in depth on top of the
     * request's PNG rule, so internal callers can't persist garbage either.
     */
    private function logo(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $binary = base64_decode($value, true);
        if ($binary === false || strncmp($binary, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return null;
        }

        return $value;
    }
}
