<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OfferType;
use App\Models\Product;
use App\Models\ProductCategory;

/**
 * P-F9 — the strict per-type `config` contract for offers.
 *
 * One place owns the five canonical shapes (the DEVICE engine is built
 * against EXACTLY these — see the pos_api mapOffer doc):
 *
 *   bogo          {"buy": {"product_ids": [], "category_ids": [], "qty": ≥1},
 *                  "get": {"same_as_buy": bool, "product_ids": [], "category_ids": [],
 *                          "qty": ≥1, "percent_off": 1..100}}
 *                  buy selector non-empty; get selector non-empty unless same_as_buy.
 *   bundle        {"price_baisas": ≥1, "groups": [{"label": str, "label_ar": str|null,
 *                  "product_ids": non-empty, "qty": ≥1}, ...] min 1}
 *   multi_buy     {"product_ids": [], "category_ids": [], "qty": ≥2, "price_baisas": ≥1}
 *                  selector non-empty.
 *   cheapest_free {"product_ids": [], "category_ids": [], "qty": ≥2,
 *                  "free_count": ≥1 AND < qty} — selector non-empty.
 *   spend_get     {"min_subtotal_baisas": ≥1, "reward_type": percent_off|fixed_off|free_product,
 *                  "reward_value": percent 1..100 | baisas ≥1 | null (free_product),
 *                  "reward_product_id": int|null — required for free_product}
 *
 * `errors()` returns human-readable problems (shape AND tenant ownership
 * of every referenced product/category id — the SetDiscountTargetsAction
 * convention); `normalize()` re-emits ONLY the canonical keys with ints
 * cast, so the stored JSON is exactly what the device expects. Money is
 * integer BAISAS throughout.
 */
final class OfferConfig
{
    public const REWARD_TYPES = ['percent_off', 'fixed_off', 'free_product'];

    /**
     * Validate shape + tenant ownership. Empty list = valid.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function errors(OfferType $type, array $config, int $companyId): array
    {
        $errors = match ($type) {
            OfferType::Bogo => self::bogoErrors($config),
            OfferType::Bundle => self::bundleErrors($config),
            OfferType::MultiBuy => self::multiBuyErrors($config),
            OfferType::CheapestFree => self::cheapestFreeErrors($config),
            OfferType::SpendGet => self::spendGetErrors($config),
        };

        if ($errors !== []) {
            return $errors;
        }

        return self::tenantErrors($type, $config, $companyId);
    }

    /**
     * The canonical, key-exact config persisted + emitted to the device.
     * Call ONLY after errors() returned []. Unknown keys are dropped.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalize(OfferType $type, array $config): array
    {
        return match ($type) {
            OfferType::Bogo => [
                'buy' => [
                    'product_ids' => self::ids($config['buy']['product_ids'] ?? []),
                    'category_ids' => self::ids($config['buy']['category_ids'] ?? []),
                    'qty' => (int) $config['buy']['qty'],
                ],
                'get' => [
                    'same_as_buy' => (bool) ($config['get']['same_as_buy'] ?? false),
                    'product_ids' => self::ids($config['get']['product_ids'] ?? []),
                    'category_ids' => self::ids($config['get']['category_ids'] ?? []),
                    'qty' => (int) $config['get']['qty'],
                    'percent_off' => (int) $config['get']['percent_off'],
                ],
            ],
            OfferType::Bundle => [
                'price_baisas' => (int) $config['price_baisas'],
                'groups' => array_values(array_map(static fn (array $g): array => [
                    'label' => trim((string) $g['label']),
                    'label_ar' => isset($g['label_ar']) && trim((string) $g['label_ar']) !== ''
                        ? trim((string) $g['label_ar'])
                        : null,
                    'product_ids' => self::ids($g['product_ids']),
                    'qty' => (int) $g['qty'],
                ], $config['groups'])),
            ],
            OfferType::MultiBuy => [
                'product_ids' => self::ids($config['product_ids'] ?? []),
                'category_ids' => self::ids($config['category_ids'] ?? []),
                'qty' => (int) $config['qty'],
                'price_baisas' => (int) $config['price_baisas'],
            ],
            OfferType::CheapestFree => [
                'product_ids' => self::ids($config['product_ids'] ?? []),
                'category_ids' => self::ids($config['category_ids'] ?? []),
                'qty' => (int) $config['qty'],
                'free_count' => (int) $config['free_count'],
            ],
            OfferType::SpendGet => [
                'min_subtotal_baisas' => (int) $config['min_subtotal_baisas'],
                'reward_type' => (string) $config['reward_type'],
                'reward_value' => $config['reward_type'] === 'free_product'
                    ? null
                    : ($config['reward_type'] === 'percent_off'
                        ? (float) $config['reward_value']
                        : (int) $config['reward_value']),
                'reward_product_id' => $config['reward_type'] === 'free_product'
                    ? (int) $config['reward_product_id']
                    : null,
            ],
        };
    }

    // =================== PER-TYPE SHAPES ===================

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function bogoErrors(array $config): array
    {
        $errors = [];

        $buy = $config['buy'] ?? null;
        if (! is_array($buy)) {
            return ['bogo config requires a "buy" object.'];
        }
        $get = $config['get'] ?? null;
        if (! is_array($get)) {
            return ['bogo config requires a "get" object.'];
        }

        $errors = [...$errors, ...self::selectorErrors($buy, 'buy')];
        if (! self::isIntAtLeast($buy['qty'] ?? null, 1)) {
            $errors[] = 'buy.qty must be an integer ≥ 1.';
        }
        if (self::selectorEmpty($buy)) {
            $errors[] = 'buy needs at least one product or category.';
        }

        $sameAsBuy = $get['same_as_buy'] ?? false;
        if (! is_bool($sameAsBuy)) {
            $errors[] = 'get.same_as_buy must be a boolean.';
            $sameAsBuy = false;
        }
        $errors = [...$errors, ...self::selectorErrors($get, 'get')];
        if (! self::isIntAtLeast($get['qty'] ?? null, 1)) {
            $errors[] = 'get.qty must be an integer ≥ 1.';
        }
        if (! self::isIntAtLeast($get['percent_off'] ?? null, 1) || (int) $get['percent_off'] > 100) {
            $errors[] = 'get.percent_off must be an integer between 1 and 100.';
        }
        if (! $sameAsBuy && self::selectorEmpty($get)) {
            $errors[] = 'get needs at least one product or category (or set same_as_buy).';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function bundleErrors(array $config): array
    {
        $errors = [];

        if (! self::isIntAtLeast($config['price_baisas'] ?? null, 1)) {
            $errors[] = 'price_baisas must be an integer ≥ 1.';
        }

        $groups = $config['groups'] ?? null;
        if (! is_array($groups) || $groups === [] || array_is_list($groups) === false) {
            $errors[] = 'bundle config requires a non-empty "groups" list.';

            return $errors;
        }

        foreach ($groups as $i => $group) {
            if (! is_array($group)) {
                $errors[] = sprintf('groups.%d must be an object.', $i);
                continue;
            }
            if (! is_string($group['label'] ?? null) || trim((string) $group['label']) === '') {
                $errors[] = sprintf('groups.%d.label is required.', $i);
            }
            if (isset($group['label_ar']) && $group['label_ar'] !== null && ! is_string($group['label_ar'])) {
                $errors[] = sprintf('groups.%d.label_ar must be a string or null.', $i);
            }
            if (! self::isIdList($group['product_ids'] ?? null) || ($group['product_ids'] ?? []) === []) {
                $errors[] = sprintf('groups.%d.product_ids must be a non-empty list of product ids.', $i);
            }
            if (! self::isIntAtLeast($group['qty'] ?? null, 1)) {
                $errors[] = sprintf('groups.%d.qty must be an integer ≥ 1.', $i);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function multiBuyErrors(array $config): array
    {
        $errors = self::selectorErrors($config, 'config');
        if (self::selectorEmpty($config)) {
            $errors[] = 'multi_buy needs at least one product or category.';
        }
        if (! self::isIntAtLeast($config['qty'] ?? null, 2)) {
            $errors[] = 'qty must be an integer ≥ 2.';
        }
        if (! self::isIntAtLeast($config['price_baisas'] ?? null, 1)) {
            $errors[] = 'price_baisas must be an integer ≥ 1.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function cheapestFreeErrors(array $config): array
    {
        $errors = self::selectorErrors($config, 'config');
        if (self::selectorEmpty($config)) {
            $errors[] = 'cheapest_free needs at least one product or category.';
        }
        if (! self::isIntAtLeast($config['qty'] ?? null, 2)) {
            $errors[] = 'qty must be an integer ≥ 2.';
        }
        if (! self::isIntAtLeast($config['free_count'] ?? null, 1)) {
            $errors[] = 'free_count must be an integer ≥ 1.';
        } elseif (self::isIntAtLeast($config['qty'] ?? null, 2)
            && (int) $config['free_count'] >= (int) $config['qty']) {
            $errors[] = 'free_count must be less than qty.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function spendGetErrors(array $config): array
    {
        $errors = [];

        if (! self::isIntAtLeast($config['min_subtotal_baisas'] ?? null, 1)) {
            $errors[] = 'min_subtotal_baisas must be an integer ≥ 1.';
        }

        $rewardType = $config['reward_type'] ?? null;
        if (! is_string($rewardType) || ! in_array($rewardType, self::REWARD_TYPES, true)) {
            $errors[] = 'reward_type must be one of: '.implode(', ', self::REWARD_TYPES).'.';

            return $errors;
        }

        $value = $config['reward_value'] ?? null;
        if ($rewardType === 'percent_off') {
            if (! is_numeric($value) || (float) $value < 1 || (float) $value > 100) {
                $errors[] = 'reward_value must be a percent between 1 and 100.';
            }
        } elseif ($rewardType === 'fixed_off') {
            if (! self::isIntAtLeast($value, 1)) {
                $errors[] = 'reward_value must be an integer baisas amount ≥ 1.';
            }
        } else { // free_product
            if ($value !== null) {
                $errors[] = 'reward_value must be null for a free_product reward.';
            }
            if (! self::isIntAtLeast($config['reward_product_id'] ?? null, 1)) {
                $errors[] = 'reward_product_id is required for a free_product reward.';
            }
        }

        if ($rewardType !== 'free_product'
            && isset($config['reward_product_id'])
            && $config['reward_product_id'] !== null) {
            $errors[] = 'reward_product_id is only allowed for a free_product reward.';
        }

        return $errors;
    }

    // =================== TENANT OWNERSHIP ===================

    /**
     * Every product/category id referenced anywhere in the config MUST
     * belong to the company (the SetDiscountTargetsAction convention).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function tenantErrors(OfferType $type, array $config, int $companyId): array
    {
        [$productIds, $categoryIds] = self::referencedIds($type, $config);

        $errors = [];
        if ($productIds !== []) {
            $owned = Product::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $productIds)
                ->count();
            if ($owned !== count($productIds)) {
                $errors[] = 'One or more products in the offer do not belong to your company.';
            }
        }
        if ($categoryIds !== []) {
            $owned = ProductCategory::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $categoryIds)
                ->count();
            if ($owned !== count($categoryIds)) {
                $errors[] = 'One or more categories in the offer do not belong to your company.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function referencedIds(OfferType $type, array $config): array
    {
        $products = [];
        $categories = [];

        switch ($type) {
            case OfferType::Bogo:
                $products = [...self::ids($config['buy']['product_ids'] ?? []), ...self::ids($config['get']['product_ids'] ?? [])];
                $categories = [...self::ids($config['buy']['category_ids'] ?? []), ...self::ids($config['get']['category_ids'] ?? [])];
                break;
            case OfferType::Bundle:
                foreach ($config['groups'] as $group) {
                    $products = [...$products, ...self::ids($group['product_ids'])];
                }
                break;
            case OfferType::MultiBuy:
            case OfferType::CheapestFree:
                $products = self::ids($config['product_ids'] ?? []);
                $categories = self::ids($config['category_ids'] ?? []);
                break;
            case OfferType::SpendGet:
                if (($config['reward_type'] ?? null) === 'free_product') {
                    $products = [(int) $config['reward_product_id']];
                }
                break;
        }

        return [array_values(array_unique($products)), array_values(array_unique($categories))];
    }

    // =================== PRIMITIVES ===================

    /**
     * Both selector keys, when present, must be lists of integers.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function selectorErrors(array $node, string $path): array
    {
        $errors = [];
        foreach (['product_ids', 'category_ids'] as $key) {
            if (isset($node[$key]) && ! self::isIdList($node[$key])) {
                $errors[] = sprintf('%s.%s must be a list of integer ids.', $path, $key);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function selectorEmpty(array $node): bool
    {
        return self::ids($node['product_ids'] ?? []) === []
            && self::ids($node['category_ids'] ?? []) === [];
    }

    private static function isIdList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $id) {
            if (! self::isIntAtLeast($id, 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strict integer check: a real int, or an integer-valued numeric
     * string (JSON over multipart forms) — floats / junk refused.
     */
    private static function isIntAtLeast(mixed $value, int $min): bool
    {
        if (is_int($value)) {
            return $value >= $min;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value >= $min;
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($id): int => (int) $id, $value));
    }
}
