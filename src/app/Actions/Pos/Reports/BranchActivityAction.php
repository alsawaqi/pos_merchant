<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Branch detail activity feed + sales snapshot (v2 #11). Caller passes
 * the already tenant-verified company + branch ids.
 *
 *   - sales            : today + month-to-date paid gross/count (branch)
 *   - recent_orders    : last N orders at the branch (+ staff/customer)
 *   - recent_shifts    : last N shifts (+ staff, variance)
 *   - recent_movements : last N stock movements (+ ingredient, who)
 *
 * Money is decimal-3 OMR. Relations are eager-loaded to avoid N+1.
 */
final readonly class BranchActivityAction
{
    private const RECENT = 8;

    /**
     * @return array<string, mixed>
     */
    public function handle(int $companyId, int $branchId): array
    {
        return [
            'sales' => $this->salesSnapshot($companyId, $branchId),
            'recent_orders' => $this->recentOrders($companyId, $branchId),
            'recent_shifts' => $this->recentShifts($companyId, $branchId),
            'recent_movements' => $this->recentMovements($branchId),
        ];
    }

    /**
     * @return array{today: array{gross: string, count: int}, mtd: array{gross: string, count: int}}
     */
    private function salesSnapshot(int $companyId, int $branchId): array
    {
        $now = Carbon::now();

        return [
            'today' => $this->snap($companyId, $branchId, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'mtd' => $this->snap($companyId, $branchId, $now->copy()->startOfMonth(), $now->copy()->endOfDay()),
        ];
    }

    /**
     * @return array{gross: string, count: int}
     */
    private function snap(int $companyId, int $branchId, Carbon $from, Carbon $to): array
    {
        $row = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt')
            ->first();

        return [
            'gross' => number_format((float) ($row?->gross ?? 0), 3, '.', ''),
            'count' => (int) ($row?->cnt ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(int $companyId, int $branchId): array
    {
        return Order::query()
            ->with(['staff:id,name', 'customer:id,name'])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static fn (Order $o): array => [
                'uuid' => $o->uuid,
                'status' => $o->status?->value,
                'order_type' => $o->order_type?->value,
                'grand_total' => (string) $o->grand_total,
                'opened_at' => $o->opened_at?->format('Y-m-d\TH:i:s'),
                'staff_name' => $o->staff?->name,
                'customer_name' => $o->customer?->name,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentShifts(int $companyId, int $branchId): array
    {
        return Shift::query()
            ->with(['staff:id,name'])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static fn (Shift $s): array => [
                'uuid' => $s->uuid,
                'status' => $s->status?->value,
                'opened_at' => $s->opened_at?->format('Y-m-d\TH:i:s'),
                'closed_at' => $s->closed_at?->format('Y-m-d\TH:i:s'),
                'variance' => $s->variance !== null ? (string) $s->variance : null,
                'staff_name' => $s->staff?->name,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentMovements(int $branchId): array
    {
        return StockMovement::query()
            ->with(['ingredient:id,name,unit', 'recordedByUser:id,name', 'recordedByStaff:id,name'])
            ->where('branch_id', $branchId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static function (StockMovement $m): array {
                $unit = $m->ingredient?->unit;

                return [
                    'movement_type' => $m->movement_type?->value,
                    'quantity' => (string) $m->quantity,
                    'occurred_at' => $m->occurred_at?->format('Y-m-d\TH:i:s'),
                    'ingredient_name' => $m->ingredient?->name,
                    'unit' => $unit instanceof \BackedEnum ? $unit->value : ($unit !== null ? (string) $unit : null),
                    'recorded_by' => $m->recordedByUser?->name ?? $m->recordedByStaff?->name,
                ];
            })->all();
    }
}
