<?php

declare(strict_types=1);

namespace App\Support\Costs;

use Illuminate\Support\Facades\DB;

/**
 * LAUNCH costs & allergens add-on (tester calls 1 and 2) — the merchant's
 * two cost settings, rows of pos_company_settings (a JSON number each):
 *
 *   costs.price_alert_threshold_percent  a goods-received price per base unit
 *       that moved by at least this much from the previous purchase of the
 *       same ingredient raises a price alert (default 10)
 *   costs.target_food_cost_percent       the company's target food cost %
 *       (default 30); a product may override it
 *       (pos_products.target_food_cost_percent)
 *
 * Values are kept as 2-decimal strings ("10.00").
 */
final class CostSettings
{
    public const KEY_THRESHOLD = 'costs.price_alert_threshold_percent';

    public const KEY_TARGET = 'costs.target_food_cost_percent';

    public const DEFAULT_THRESHOLD = '10.00';

    public const DEFAULT_TARGET = '30.00';

    public static function threshold(int $companyId): string
    {
        return self::read($companyId, self::KEY_THRESHOLD, self::DEFAULT_THRESHOLD);
    }

    public static function target(int $companyId): string
    {
        return self::read($companyId, self::KEY_TARGET, self::DEFAULT_TARGET);
    }

    /** A percent as a 2-decimal string, or null when blank. */
    public static function percent(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function read(int $companyId, string $key, string $default): string
    {
        $raw = DB::table('pos_company_settings')->where('company_id', $companyId)->where('key', $key)->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        $percent = self::percent($value);

        return $percent !== null && (float) $percent > 0 ? $percent : $default;
    }
}
