<?php

declare(strict_types=1);

namespace App\Support\Costs;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH costs & allergens add-on (tester call 1) — supplier price history
 * and price-change alerts, built from the goods-received lines that already
 * exist (no new data entry).
 *
 *   history   an ingredient's priced purchase lines (a line paid 0 carries
 *             no price): date, supplier, the container it was bought in,
 *             the price paid per BASE unit (the line's unit_cost, before
 *             tax) and the change from the previous purchase of the same
 *             ingredient (any supplier), oldest → newest by receipt date,
 *             then line
 *   alert     a line whose price per base unit moved from the previous
 *             purchase by at least the company threshold (|Δ| ÷ old ≥
 *             threshold %), up or down
 *
 * Alerts are not stored: changing the threshold changes which lines alert.
 * A manager marking one seen writes pos_price_alert_reviews. Every read is
 * one merchant's (the receipt's company).
 */
final class PriceHistory
{
    /**
     * The priced ingredient lines of the given ingredients (all when null),
     * oldest first, each with `previous_unit_cost` (null for the first).
     *
     * @param  list<int>|null  $ingredientIds
     * @return Collection<int, object>
     */
    public static function lines(int $companyId, ?array $ingredientIds = null): Collection
    {
        $rows = DB::table('pos_purchase_receipt_lines as l')
            ->join('pos_purchase_receipts as r', 'r.id', '=', 'l.purchase_receipt_id')
            ->leftJoin('pos_suppliers as s', 's.id', '=', 'r.supplier_id')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')
            ->where('l.item_type', 'ingredient')
            ->whereNotNull('l.ingredient_id')
            ->where('l.unit_cost', '>', 0)
            ->when($ingredientIds !== null, static fn ($q) => $q->whereIn('l.ingredient_id', $ingredientIds ?: [0]))
            ->orderBy('r.received_at')->orderBy('l.id')
            ->get([
                'l.id', 'l.ingredient_id', 'l.unit_cost', 'l.unit', 'l.quantity', 'l.line_cost', 'l.container_label', 'l.pieces',
                'l.purchase_unit', 'l.purchase_quantity', 'l.unit_price', 'r.uuid as receipt_uuid', 'r.reference as receipt_reference',
                'r.received_at', 's.uuid as supplier_uuid', 's.name as supplier_name',
            ]);

        $last = [];
        foreach ($rows as $row) {
            $id = (int) $row->ingredient_id;
            $row->unit_cost = self::cost($row->unit_cost);
            $row->previous_unit_cost = $last[$id] ?? null;
            $row->change_pct = $row->previous_unit_cost === null ? null : self::pct($row->previous_unit_cost, $row->unit_cost);
            $last[$id] = $row->unit_cost;
        }

        return $rows;
    }

    /**
     * An ingredient's history rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forIngredient(int $companyId, int $ingredientId): array
    {
        $threshold = CostSettings::threshold($companyId);

        return self::lines($companyId, [$ingredientId])->reverse()->values()
            ->map(static fn (object $row): array => self::present($row, $threshold))->all();
    }

    /**
     * The alerting lines received in [$from, $to] (newest first).
     *
     * @return Collection<int, object>
     */
    public static function alerts(int $companyId, Carbon $from, ?Carbon $to = null, ?array $lineIds = null): Collection
    {
        $threshold = CostSettings::threshold($companyId);
        $in = DB::table('pos_purchase_receipt_lines as l')
            ->join('pos_purchase_receipts as r', 'r.id', '=', 'l.purchase_receipt_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->where('l.item_type', 'ingredient')->whereNotNull('l.ingredient_id')
            ->when($lineIds === null, static fn ($q) => $q->where('r.received_at', '>=', $from)
                ->when($to !== null, static fn ($qq) => $qq->where('r.received_at', '<=', $to)))
            ->when($lineIds !== null, static fn ($q) => $q->whereIn('l.id', $lineIds ?: [0]))
            ->pluck('l.ingredient_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($in === []) {
            return collect();
        }

        return self::lines($companyId, $in)
            ->filter(static function (object $row) use ($from, $to, $lineIds, $threshold): bool {
                if ($lineIds !== null ? ! in_array((int) $row->id, $lineIds, true)
                    : (Carbon::parse($row->received_at)->lt($from) || ($to !== null && Carbon::parse($row->received_at)->gt($to)))) {
                    return false;
                }

                return self::alerting($row, $threshold);
            })
            ->reverse()->values();
    }

    /** Whether a line moved by at least the threshold from the previous purchase. */
    public static function alerting(object $row, string $threshold): bool
    {
        if ($row->previous_unit_cost === null || ! BigDecimal::of($row->previous_unit_cost)->isPositive()) {
            return false;
        }
        // |new − old| × 100 ≥ threshold × old, exactly.
        $delta = BigDecimal::of($row->unit_cost)->minus($row->previous_unit_cost)->abs()->multipliedBy(100);

        return $delta->isGreaterThanOrEqualTo(BigDecimal::of($threshold)->multipliedBy($row->previous_unit_cost));
    }

    /** @return array<string, mixed> */
    public static function present(object $row, string $threshold): array
    {
        return [
            'line_id' => (int) $row->id,
            'receipt_uuid' => (string) $row->receipt_uuid,
            'receipt_reference' => $row->receipt_reference,
            'received_at' => Carbon::parse($row->received_at)->toIso8601String(),
            'supplier' => $row->supplier_uuid !== null ? ['uuid' => (string) $row->supplier_uuid, 'name' => (string) $row->supplier_name] : null,
            'container_label' => $row->container_label,
            'pieces' => $row->pieces !== null ? (string) BigDecimal::of((string) $row->pieces)->stripTrailingZeros() : null,
            'purchase_unit' => $row->purchase_unit,
            'unit' => $row->unit,
            'unit_cost' => $row->unit_cost,
            'previous_unit_cost' => $row->previous_unit_cost,
            'change' => $row->previous_unit_cost === null ? null
                : (string) BigDecimal::of($row->unit_cost)->minus($row->previous_unit_cost)->toScale(6, RoundingMode::HALF_UP),
            'change_pct' => $row->change_pct,
            'alert' => self::alerting($row, $threshold),
        ];
    }

    /** The cost per base unit as a 6-decimal string. */
    private static function cost(mixed $value): string
    {
        if (is_float($value)) {
            $value = number_format($value, 6, '.', '');
        }

        return (string) BigDecimal::of((string) $value)->toScale(6, RoundingMode::HALF_UP);
    }

    private static function pct(string $old, string $new): ?float
    {
        $from = BigDecimal::of($old);
        if (! $from->isPositive()) {
            return null;
        }

        return (float) (string) BigDecimal::of($new)->minus($from)->multipliedBy(100)->dividedBy($from, 1, RoundingMode::HALF_UP);
    }
}
